<?php

use App\Support\Dev\PreparerStartupCheck;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Process;

it('adds the application-preparer to the dev command group and keeps every existing service', function () {
    $commands = collect(DevCommands::commands())->keyBy('name');

    // Every service the dev group already had...
    foreach (['server', 'queue', 'logs', 'vite', 'scheduler'] as $name) {
        expect($commands->has($name))->toBeTrue("the {$name} dev process is missing");
    }
    expect($commands['server']['command'])->toBe('php artisan serve')
        ->and($commands['queue']['command'])->toBe('php artisan queue:listen --tries=1 --timeout=0')
        ->and($commands['scheduler']['command'])->toBe('php artisan schedule:work');

    // ...plus the preparer, once, through the existing wrapper in dev mode.
    expect($commands->has('preparer'))->toBeTrue()
        ->and($commands['preparer']['command'])->toBe('cd workers/application-preparer && npm run agent:dev')
        ->and(collect(DevCommands::commands())->where('name', 'preparer'))->toHaveCount(1);
});

it('does not start the browser inspector, migrate, or prepare anything from the dev group', function () {
    $joined = collect(DevCommands::commands())->pluck('command')->implode("\n");

    expect($joined)->not->toContain('browser-inspector')->not->toContain('migrate')->not->toContain('preparations')
        ->and(collect(DevCommands::commands())->pluck('name')->all())->not->toContain('inspector');
});

it('wires the preparer to the existing wrapper script that is really there', function () {
    $package = json_decode((string) file_get_contents(base_path('workers/application-preparer/package.json')), true);

    expect($package['scripts']['agent:dev'])->toBe('node src/start.ts --dev')
        ->and($package['scripts']['agent'])->toBe('node src/start.ts')
        ->and(is_file(base_path('workers/application-preparer/src/start.ts')))->toBeTrue();
});

it('reports ready when the preparer\'s own check passes', function () {
    Process::fake(['*' => Process::result("[agent] ready: settings and Chromium found.\n")]);

    expect((new PreparerStartupCheck)->run())->toBe(['ready' => true, 'output' => '[agent] ready: settings and Chromium found.']);
    Process::assertRan(fn ($p) => $p->command === 'node src/start.ts --check' && str_ends_with((string) $p->path, 'workers/application-preparer'));
});

it('relays the preparer\'s own explanation when it cannot start, without touching any setting itself', function () {
    Process::fake(['*' => Process::result('', "[agent] Cannot start: no PREPARER_WORKER_TOKEN. Set it in the repository .env.\n", 1)]);

    $check = (new PreparerStartupCheck)->run();

    expect($check['ready'])->toBeFalse()
        ->and($check['output'])->toContain('no PREPARER_WORKER_TOKEN');
});

it('replaces a raw runtime error (such as an old Node) with a plain instruction', function () {
    Process::fake(['*' => Process::result('', "SyntaxError: Unexpected token ':'\n", 1)]);

    $check = (new PreparerStartupCheck)->run();

    expect($check['ready'])->toBeFalse()
        ->and($check['output'])->toContain('Node 22.18 or newer')->not->toContain('SyntaxError');
});

it('says so when node cannot be run at all', function () {
    Process::fake(['*' => fn () => throw new RuntimeException('no node')]);

    expect((new PreparerStartupCheck)->run())->toMatchArray(['ready' => false])
        ->and((new PreparerStartupCheck)->run()['output'])->toContain('Node');
});
