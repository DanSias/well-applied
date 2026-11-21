<?php

use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;

require_once __DIR__.'/JobicyFixtures.php';

beforeEach(function () {
    Sleep::fake();
    $this->out = sys_get_temp_dir().'/jobicy-sample-'.uniqid().'.json';
    config(['services.jobicy_commercial.enabled' => true, 'services.jobicy_commercial.base_url' => 'https://jobicy.test/api/jobs', 'services.jobicy_commercial.api_key' => JBC_KEY]);
});
afterEach(fn () => file_exists($this->out) && unlink($this->out));

function jbcCommand(object $test, array $options = []): PendingCommand
{
    return $test->artisan('jobicy:commercial-sample', array_merge(['--count' => 24, '--max-cost-cents' => '100', '--confirm-spend' => true, '--out' => $test->out], $options));
}

it('refuses unless EVERY gate is met, and makes no request when it refuses', function (array $config, array $options, string $message) {
    config($config);
    Http::fake();

    jbcCommand($this, $options)->expectsOutputToContain($message)->assertExitCode(2);
    Http::assertNothingSent();
    expect(file_exists($this->out))->toBeFalse();
})->with([
    'disabled' => [['services.jobicy_commercial.enabled' => false], [], 'JOBICY_COMMERCIAL_ENABLED'],
    'no key' => [['services.jobicy_commercial.api_key' => null], [], 'No Jobicy Commercial key'],
    'no confirmation' => [[], ['--confirm-spend' => false], '--confirm-spend'],
    'no budget' => [[], ['--max-cost-cents' => null], '--max-cost-cents is required'],
    'budget below the worst case' => [[], ['--max-cost-cents' => '23'], 'must cover the worst case'],
    'budget above the ceiling' => [[], ['--max-cost-cents' => '201', '--count' => 100], 'may not exceed 200'],
    'budget not a number' => [[], ['--max-cost-cents' => 'lots'], '--max-cost-cents is required'],
    'count too large' => [[], ['--count' => 101], '--count must be 1 to 100'],
]);

it('refuses to overwrite an existing report', function () {
    file_put_contents($this->out, 'keep me');
    Http::fake();

    jbcCommand($this)->expectsOutputToContain('does not exist yet')->assertExitCode(2);
    expect(file_get_contents($this->out))->toBe('keep me');
    Http::assertNothingSent();
});

it('runs the sample, writes a sanitised report, and touches no database row', function () {
    [$public, $commercial, $total] = jbcPair();
    Http::fake(fn ($request) => Http::response($request->hasHeader('Authorization') ? jbcEnvelope($commercial, $total) : jbcEnvelope($public)));
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $jobs = JobPosting::count();
    $queries = [];

    jbcCommand($this)->expectsOutputToContain('status: completed')->assertExitCode(0);

    $report = json_decode(file_get_contents($this->out), true);
    expect($report['run']['status'])->toBe('completed')->and($report['totals']['matched'])->toBe(19)->and($report['scope']['historical_inventory'])->toBeFalse();
    expect(file_get_contents($this->out))->not->toContain(JBC_KEY)->not->toContain('SECRET')->not->toContain('?');
    expect($queries)->toBe([])->and(JobPosting::count())->toBe($jobs);
    Http::assertSentCount(2);
});

it('exits non-zero when the run stops, and still writes what happened', function () {
    Http::fake(fn ($request) => $request->hasHeader('Authorization') ? Http::response(['error' => 'x'], 429, ['Retry-After' => '30']) : Http::response(jbcEnvelope(jbcPair()[0])));

    jbcCommand($this)->assertExitCode(1);

    expect(json_decode(file_get_contents($this->out), true)['run'])->toMatchArray(['status' => 'rate_limited', 'retry_after_seconds' => 30]);
    Http::assertSentCount(2);
});

it('is not part of discovery or any schedule', function () {
    $console = file_get_contents(base_path('routes/console.php')).file_get_contents(base_path('bootstrap/app.php'));

    expect($console)->not->toContain('jobicy:commercial')->and($console)->not->toContain('JobicyCommercial');
    $finder = collect(glob(base_path('app/Support/JobDiscovery/*.php')))->merge(glob(base_path('app/Support/JobDiscovery/Providers/*.php')));
    foreach ($finder as $file) {
        expect(file_get_contents($file))->not->toContain('JobicyCommercial', basename($file));
    }
});

it('does not change the existing Jobicy discovery provider or its configuration', function () {
    $base = trim((string) shell_exec('git -C '.escapeshellarg(base_path()).' diff --name-only master -- app/Support/JobDiscovery/Providers/JobicyDiscoveryProvider.php app/Support/JobDiscovery/IngestDiscoveredCandidate.php app/Support/JobDiscovery/RunJobDiscovery.php 2>/dev/null'));
    expect($base)->toBe('');
    expect(config('services.jobicy.base_url'))->toBe('https://jobicy.com/api/v2/remote-jobs');
});
