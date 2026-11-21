<?php

use App\Enums\JobDisposition;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\JobFreshness\Demo\DemoGuard;
use App\Support\JobFreshness\Demo\DemoRefused;
use App\Support\JobFreshness\Demo\DemoSeeder;
use App\Support\JobFreshness\JobFreshness;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function dgDir(): string
{
    $d = realpath(sys_get_temp_dir()).'/fdemo_'.uniqid();
    mkdir($d);

    return $d;
}

/** a context in which everything is right, with overrides */
function dgCtx(string $demoFile, array $over = []): array
{
    return $over + ['demo' => true, 'active_driver' => 'sqlite', 'active_database' => $demoFile, 'queue' => 'discard', 'production' => [database_path('database.sqlite')]];
}

function dgRefusal(string $candidate, array $ctx): ?string
{
    try {
        DemoGuard::assertSafeToSeed($candidate, $ctx);
    } catch (DemoRefused $e) {
        return $e->reason;
    }

    return null;
}

// ---- the guard ---------------------------------------------------------------------------------------------------------------------------------

it('accepts a demo file in a scratch directory when every condition holds', function () {
    $file = dgDir().'/freshness-demo-1.sqlite';

    expect(dgRefusal($file, dgCtx($file)))->toBeNull();
});

it('refuses without the explicit demo marker', function () {
    $file = dgDir().'/freshness-demo-1.sqlite';

    expect(dgRefusal($file, dgCtx($file, ['demo' => false])))->toBe('demo_marker_missing');
});

it('refuses a path that is not an absolute freshness-demo*.sqlite file', function (string $name) {
    $dir = dgDir();
    $file = $name === '' ? '' : (str_starts_with($name, '/') || str_starts_with($name, 'relative/') || $name === ':memory:' || str_starts_with($name, 'file:') ? $name : $dir.'/'.$name);

    expect(dgRefusal($file, dgCtx($file)))->toBe('not_a_demo_path');
})->with(['', ':memory:', 'file:x.sqlite?mode=memory', 'database.sqlite', 'freshness-demo.db', 'my-demo.sqlite', 'relative/freshness-demo.sqlite', '/freshness-demo.txt']);

it('refuses the production database by path, relative spelling, symlink, hard link and directory', function () {
    $prod = tempnam(sys_get_temp_dir(), 'prod');
    $dir = dgDir();
    $ctxFor = fn (string $file) => dgCtx($file, ['production' => [$prod]]);

    // symlink named like a demo file, pointing at "production"
    symlink($prod, $dir.'/freshness-demo-link.sqlite');
    expect(dgRefusal($dir.'/freshness-demo-link.sqlite', $ctxFor($dir.'/freshness-demo-link.sqlite')))->toBe('production_database');

    // hard link
    link($prod, $dir.'/freshness-demo-hard.sqlite');
    expect(dgRefusal($dir.'/freshness-demo-hard.sqlite', $ctxFor($dir.'/freshness-demo-hard.sqlite')))->toBe('production_database');

    // the same file reached through ".."
    $alias = dirname($prod).'/../'.basename(dirname($prod)).'/'.basename($prod);
    expect(dgRefusal($alias, dgCtx($alias, ['production' => [$prod]])))->toBe('not_a_demo_path');

    // a demo-named file inside the project's database directory
    $inDb = database_path('freshness-demo-x.sqlite');
    expect(dgRefusal($inDb, dgCtx($inDb)))->toBe('production_database');

    // the real production path, and a demo-named copy of its spelling
    expect(dgRefusal(database_path('database.sqlite'), dgCtx(database_path('database.sqlite'))))->toBe('not_a_demo_path');
    unlink($prod);
});

it('refuses when this process is not already running on the demo file, or its queue is real', function () {
    $dir = dgDir();
    $file = $dir.'/freshness-demo-1.sqlite';

    expect(dgRefusal($file, dgCtx($file, ['active_database' => database_path('database.sqlite')])))->toBe('active_database_mismatch')
        ->and(dgRefusal($file, dgCtx($file, ['active_database' => $dir.'/freshness-demo-other.sqlite'])))->toBe('active_database_mismatch')
        ->and(dgRefusal($file, dgCtx($file, ['active_driver' => 'mysql'])))->toBe('active_database_mismatch')
        ->and(dgRefusal($file, dgCtx($file, ['queue' => 'sync'])))->toBe('queue_not_discard')
        ->and(dgRefusal($file, dgCtx($file, ['queue' => 'database'])))->toBe('queue_not_discard');
});

it('refuses a file that already holds job rows unless it carries the demo marker', function () {
    $file = dgDir().'/freshness-demo-1.sqlite';
    $pdo = new PDO('sqlite:'.$file);
    $pdo->exec('create table job_postings (id integer primary key, title text)');
    $pdo->exec("insert into job_postings (title) values ('a real job')");

    expect(dgRefusal($file, dgCtx($file)))->toBe('not_a_demo_database');

    $pdo->exec('PRAGMA user_version = '.DemoGuard::USER_VERSION_MARKER);
    expect(dgRefusal($file, dgCtx($file)))->toBeNull();
});

it('isolates a demo process: no production file, no real queue, and no network', function () {
    $dir = dgDir();
    $file = $dir.'/freshness-demo-1.sqlite';

    expect(fn () => DemoGuard::assertProcessIsolated(dgCtx(database_path('database.sqlite'), ['active_database' => database_path('database.sqlite')])))->toThrow(DemoRefused::class, 'production')
        ->and(fn () => DemoGuard::assertProcessIsolated(dgCtx($file, ['queue' => 'sync'])))->toThrow(DemoRefused::class, 'QUEUE_CONNECTION=discard')
        ->and(fn () => DemoGuard::assertProcessIsolated(dgCtx($file, ['active_driver' => 'mysql'])))->toThrow(DemoRefused::class, 'SQLite');

    DemoGuard::assertProcessIsolated(dgCtx($file));
    expect(fn () => Http::get('https://himalayas.app/jobs/api/companies/a/jobs/b'))->toThrow(StrayRequestException::class);
});

it('refuses the command in this (non-demo) process before it migrates or writes anything', function () {
    $file = dgDir().'/freshness-demo-1.sqlite';
    $rows = JobPosting::count();

    $this->artisan('freshness:demo', ['--database' => $file])->expectsOutputToContain('demo_marker_missing')->assertFailed();
    config(['job_freshness.demo' => true]);
    $this->artisan('freshness:demo', ['--database' => $file])->expectsOutputToContain('active_database_mismatch')->assertFailed();

    expect(file_exists($file))->toBeFalse()->and(JobPosting::count())->toBe($rows);
});

it('does nothing at boot in a normal process: the flag is off and the default queue is untouched', function () {
    expect(config('job_freshness.demo'))->toBeFalse()->and(file_get_contents(base_path('.env.example')))->not->toContain('FRESHNESS_DEMO');
});

// ---- the seeded states ---------------------------------------------------------------------------------------------------------------------

it('seeds fictional jobs for every freshness state, with protected expired jobs, and starts no background work or request', function () {
    config(['job_freshness.enabled' => true]);
    Queue::fake();
    Bus::fake();
    Http::fake();

    $ids = DemoSeeder::seed();

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
    expect(AgentRun::count())->toBe(0)->and(GenerationAttempt::count())->toBe(0)->and(count($ids))->toBe(10)
        ->and(JobPosting::query()->where('title', 'not like', 'DEMO:%')->count())->toBe(0);

    $label = fn (string $key) => JobFreshness::view(JobPosting::find($ids[$key]))['label'];
    expect($label('Not live-checked'))->toBe('Not live-checked')
        ->and($label('Confirmed open by the provider'))->toBe('Confirmed open')
        ->and($label('Possibly closed (reported once)'))->toBe('May be closed')
        ->and($label('Check inconclusive'))->toBe('Check inconclusive')
        ->and($label('Expired (known expiry date)'))->toBe('Expired')
        ->and($label('Closed (confirmed twice)'))->toBe('Closed')
        ->and($label('Jobicy, not live-checked'))->toBe('Not live-checked')
        ->and($label('Jobicy, confirmed open'))->toBe('Confirmed open');
});

it('keeps the protected demo jobs reachable while the other expired demo job is left out of the Inbox', function () {
    config(['job_freshness.enabled' => true]);
    $ids = DemoSeeder::seed();
    $pursued = $ids['Expired but PURSUED'];
    $applied = $ids['Expired but has an APPLICATION'];

    $inbox = collect($this->get('/jobs')->viewData('page')['props']['jobs'])->pluck('id');
    $opps = collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->pluck('id');

    expect($inbox)->toContain($applied)->not->toContain($ids['Expired (known expiry date)'])->not->toContain($ids['Closed (confirmed twice)'])->not->toContain($pursued)
        ->and($opps)->toContain($pursued)
        ->and($this->get('/jobs')->viewData('page')['props']['hidden_inactive'])->toBe(2, 'the expired demo job and the one confirmed closed by recorded observations (effective freshness, no status written)');
    $this->get(route('opportunities.show', $pursued))->assertOk();
    $this->get(route('jobs.show', $applied))->assertOk();
    expect(Application::count())->toBe(1)->and(JobPosting::find($pursued)->disposition)->toBe(JobDisposition::Pursued);
});

// ---- end to end in a real, separate process -------------------------------------------------------------------------------------------------

function dgRun(array $env, string $database): ProcessResult
{
    return Process::path(base_path())->timeout(180)->env($env + ['APP_ENV' => 'local', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'DB_URL' => ''])->run(['php', 'artisan', 'freshness:demo', '--database='.$database]);
}

it('creates and seeds a disposable demo database in its own process, and nothing else', function () {
    $dir = dgDir();
    $file = $dir.'/freshness-demo-e2e.sqlite';
    $env = ['FRESHNESS_DEMO' => '1', 'QUEUE_CONNECTION' => 'discard', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file];

    $first = dgRun($env, $file);

    expect($first->exitCode())->toBe(0, $first->output().$first->errorOutput())->and($first->output())->toContain('10 fictional jobs');
    $pdo = new PDO('sqlite:'.$file);
    $count = fn (string $t) => (int) $pdo->query("select count(*) from {$t}")->fetchColumn();
    expect($count('job_postings'))->toBe(10)->and($count('applications'))->toBe(1)->and($count('agent_runs'))->toBe(0)->and($count('generation_attempts'))->toBe(0)->and($count('jobs'))->toBe(0)
        ->and((int) $pdo->query('PRAGMA user_version')->fetchColumn())->toBe(DemoGuard::USER_VERSION_MARKER)
        ->and((int) $pdo->query("select count(*) from job_postings where title not like 'DEMO:%'")->fetchColumn())->toBe(0);

    $again = dgRun($env, $file);   // idempotent: nothing is duplicated
    expect($again->exitCode())->toBe(0)->and($again->output())->toContain('already seeded')->and((int) (new PDO('sqlite:'.$file))->query('select count(*) from job_postings')->fetchColumn())->toBe(10);
});

it('refuses in a real process when the marker, the queue or the database does not match', function (array $env, string $reason) {
    $dir = dgDir();
    $file = $dir.'/freshness-demo-refused.sqlite';

    $r = dgRun($env + ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file], $file);

    expect($r->exitCode())->not->toBe(0)->and($r->output().$r->errorOutput())->toContain($reason)
        ->and(file_exists($file))->toBeFalse();
})->with([
    'no marker' => [['FRESHNESS_DEMO' => '0', 'QUEUE_CONNECTION' => 'discard'], 'demo_marker_missing'],
    'a real queue' => [['FRESHNESS_DEMO' => '1', 'QUEUE_CONNECTION' => 'sync'], 'QUEUE_CONNECTION=discard'],
]);

it('refuses a real process pointed at the production database file, before it opens it', function () {
    $production = database_path('database.sqlite');
    $r = Process::path(base_path())->timeout(60)->env(['FRESHNESS_DEMO' => '1', 'QUEUE_CONNECTION' => 'discard', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $production, 'APP_ENV' => 'local', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'DB_URL' => ''])
        ->run(['php', 'artisan', 'freshness:demo', '--database='.$production]);

    expect($r->exitCode())->not->toBe(0)->and($r->output().$r->errorOutput())->toContain('production');
});

it('discards anything dispatched on the demo queue: it is neither run nor stored', function () {
    config(['queue.default' => 'discard']);
    $ran = false;

    dispatch(function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeFalse()->and(DB::table('jobs')->count())->toBe(0)->and(config('queue.connections.discard.driver'))->toBe('null');
});
