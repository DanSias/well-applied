<?php

use App\Enums\JobDisposition;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\JobFreshness\JobFreshness;
use App\Support\JobFreshness\Observation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/SurfacesTest.php';

function prObs(string $result, string $basis, int $hoursAgo, string $source = 'job_endpoint'): array
{
    return (new Observation('himalayas', $source, $result, $basis, CarbonImmutable::now()->subHours($hoursAgo)))->toArray();
}

function prJob(array $observations = [], array $attributes = [], ?int $expiresAt = null): JobPosting
{
    return frJob($attributes, $expiresAt, $observations === [] ? [] : ['liveness' => ['schema' => 1, 'observations' => $observations]]);
}

// ---- the three kinds of "open" ------------------------------------------------------------------------------------------------------------

it('tells a provider-confirmed open job apart from one that was never live-checked', function () {
    frOn();
    $confirmed = prJob([prObs('active', 'http_200', 3)]);
    $never = prJob();

    $c = JobFreshness::view($confirmed);
    $n = JobFreshness::view($never);

    expect($c)->toMatchArray(['state' => 'active', 'label' => 'Confirmed open', 'tone' => 'ok', 'checked' => true, 'actionable' => true])
        ->and($c['confirmed_at'])->toBeString()->and($c['detail'])->toContain('provider confirmed');
    expect($n)->toMatchArray(['state' => 'unchecked', 'label' => 'Not live-checked', 'tone' => 'neutral', 'checked' => false, 'actionable' => true, 'confirmed_at' => null])
        ->and($n['detail'])->toContain('only as far as our records go');
});

it('reserves the warning tone for known expiry, confirmed closure and a closure reported once', function (array $observations, ?int $expiresAt, string $label, string $tone, bool $actionable) {
    frOn();
    $v = JobFreshness::view(prJob($observations, [], $expiresAt === -1 ? now()->subDay()->timestamp : $expiresAt));

    expect([$v['label'], $v['tone'], $v['actionable']])->toBe([$label, $tone, $actionable]);
})->with([
    'known expiry' => [[], -1, 'Expired', 'warn', false],
    'confirmed closure' => [[prObs('closed', 'http_410', 40), prObs('closed', 'http_410', 1)], null, 'Closed', 'warn', false],
    'closed once (not yet confirmed)' => [[prObs('closed', 'http_410', 1)], null, 'May be closed', 'warn', true],
    'only inconclusive checks (404, timeouts)' => [[prObs('uncertain', 'http_404', 5), prObs('uncertain', 'network_error', 2)], null, 'Check inconclusive', 'neutral', true],
    'confirmed open' => [[prObs('active', 'http_200', 1)], null, 'Confirmed open', 'ok', true],
    'never checked' => [[], null, 'Not live-checked', 'neutral', true],
]);

it('says a job whose last checks were inconclusive is not thereby closed', function () {
    frOn();
    $v = JobFreshness::view(prJob(array_map(fn ($h) => prObs('uncertain', 'http_404', $h), range(1, 20))));

    expect($v['state'])->toBe('uncertain')->and($v['tone'])->toBe('neutral')->and($v['actionable'])->toBeTrue()->and($v['detail'])->toContain('not a sign the job is closed');
});

// ---- the "left out" count respects the search and every filter ---------------------------------------------------------------------------------

it('counts the jobs left out over exactly the view the list is: search, source, disposition and the derived filters', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $future = now()->addWeek()->timestamp;
    frJob(['title' => 'Staff Engineer', 'disposition' => JobDisposition::New], $future);
    frJob(['title' => 'Staff Designer', 'disposition' => JobDisposition::New], $past);
    frJob(['title' => 'Principal Engineer', 'disposition' => JobDisposition::New], $past);
    frJob(['title' => 'Principal Engineer', 'disposition' => JobDisposition::New, 'status' => JobPostingLifecycleStatus::Closed], $future);
    frJob(['title' => 'Staff Engineer', 'company' => 'Zeta', 'disposition' => JobDisposition::Shortlisted], $past);

    $count = fn (string $query) => $this->get('/jobs'.$query)->viewData('page')['props']['hidden_inactive'];

    expect($count(''))->toBe(3, 'inbox, no filter')
        ->and($count('?q=Engineer'))->toBe(2, 'the search narrows what could be shown, so it narrows the number')
        ->and($count('?q=Designer'))->toBe(1)
        ->and($count('?q=nomatch'))->toBe(0)
        ->and($count('?disposition=shortlist'))->toBe(1)
        ->and($count('?q=Zeta&disposition=shortlist'))->toBe(1)
        ->and($count('?source=adzuna'))->toBe(0, 'another source: nothing from it is hidden')
        ->and($count('?source=himalayas'))->toBe(3);
});

it('applies the inspection, triage and resume filters to the count as it does to the list', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $inspectedExpired = frJob(['disposition' => JobDisposition::New], $past);
    $untouchedExpired = frJob(['disposition' => JobDisposition::New], $past);
    $application = Application::factory()->create(['job_posting_id' => $inspectedExpired->id]);
    // an inspection result makes the first one "inspected" for the filter; a job with an Application is never hidden, so use a second job for the inspected state
    $inspectedHidden = frJob(['disposition' => JobDisposition::New], $past);
    $inspectedHidden->update(['discovery_metadata' => $inspectedHidden->discovery_metadata]);

    expect($this->get('/jobs?status=not_inspected')->viewData('page')['props']['hidden_inactive'])->toBe(2, 'two hidden jobs have never been inspected (the one with an Application is shown, not hidden)')
        ->and($this->get('/jobs?status=inspected')->viewData('page')['props']['hidden_inactive'])->toBe(0)
        ->and($this->get('/jobs?triage=raw')->viewData('page')['props']['hidden_inactive'])->toBe(2)
        ->and($this->get('/jobs?triage=analyzed')->viewData('page')['props']['hidden_inactive'])->toBe(0)
        ->and($this->get('/jobs?resume=final')->viewData('page')['props']['hidden_inactive'])->toBe(0)
        ->and($this->get('/jobs?status=not_inspected&q=nomatch')->viewData('page')['props']['hidden_inactive'])->toBe(0);
    expect($this->get('/jobs?status=not_inspected&inactive=1')->viewData('page')['props']['pagination']['total'])->toBe(3, 'showing them adds exactly the counted two to the one already listed');
    expect($application->job_posting_id)->toBe($inspectedExpired->id);
});

it('makes "Show them" add exactly the number counted, whatever the filters', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    frJob(['title' => 'Alpha', 'disposition' => JobDisposition::New], now()->addWeek()->timestamp);
    foreach (['Alpha', 'Alpha', 'Beta'] as $t) {
        frJob(['title' => $t, 'disposition' => JobDisposition::New], $past);
    }

    foreach (['', '?q=Alpha', '?q=Beta', '?status=not_inspected', '?q=Alpha&status=not_inspected'] as $query) {
        $hidden = $this->get('/jobs'.$query)->viewData('page')['props'];
        $sep = $query === '' ? '?' : '&';
        $shown = $this->get('/jobs'.$query.$sep.'inactive=1')->viewData('page')['props'];
        expect($shown['pagination']['total'])->toBe($hidden['pagination']['total'] + $hidden['hidden_inactive'], $query);
    }
});

// ---- protected jobs stay reachable -----------------------------------------------------------------------------------------------------------

it('never hides or blocks a pursued job or one with an Application, whatever its freshness', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $pursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past);
    $closedPursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now(), 'status' => JobPostingLifecycleStatus::Closed], $past);
    $applied = frJob(['disposition' => JobDisposition::New], $past);
    $application = Application::factory()->create(['job_posting_id' => $applied->id]);

    // Opportunities: listed, labelled, and its pages open
    $list = collect($this->get('/opportunities')->viewData('page')['props']['opportunities']);
    expect($list->pluck('id')->all())->toContain($pursued->id, $closedPursued->id)
        ->and($list->firstWhere('id', $pursued->id)['freshness']['label'])->toBe('Expired');
    $this->get(route('opportunities.show', $pursued))->assertOk();
    $this->get(route('opportunities.show', $closedPursued))->assertOk();
    $this->get('/opportunities?inspect='.$pursued->id)->assertOk()->assertInertia(fn ($page) => $page->where('inspection_job.id', $pursued->id));

    // The Application's own page
    $this->get(route('applications.show', $application))->assertOk();

    // Jobs: the Inbox keeps the job with an Application, every job opens in the drawer, and `all` lists everything
    $inbox = collect($this->get('/jobs')->viewData('page')['props']['jobs'])->pluck('id')->all();
    expect($inbox)->toContain($applied->id);
    foreach ([$pursued, $closedPursued, $applied] as $job) {
        expect($this->get('/jobs?job='.$job->id)->viewData('page')['props']['selected_job']['id'])->toBe($job->id);
        $this->get(route('jobs.show', $job))->assertOk();
    }
    expect(collect($this->get('/jobs?disposition=all')->viewData('page')['props']['jobs'])->pluck('id')->all())->toContain($pursued->id, $closedPursued->id, $applied->id);
});

it('keeps pursued jobs in Home\'s continue list and counts even when they are expired', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $pursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past);
    frJob(['disposition' => JobDisposition::New], $past);

    $on = $this->get('/')->viewData('page')['props'];
    expect($on['overview']['pursued'])->toBe(1)->and($on['overview']['jobs'])->toBe(1, 'the pursued job stays; the expired inbox job is dropped');

    config(['job_freshness.enabled' => false]);
    $off = $this->get('/')->viewData('page')['props']['overview'];
    expect($off['jobs'])->toBe(2);
    expect(JobPosting::find($pursued->id))->not->toBeNull();
});

// ---- flag-off behaviour is exactly as before ----------------------------------------------------------------------------------------------

it('with the flag off, the same data gives the same lists, counts and payloads, and the new parameters change nothing', function () {
    $past = now()->subDay()->timestamp;
    frJob(['title' => 'A', 'disposition' => JobDisposition::New], $past);
    frJob(['title' => 'B', 'disposition' => JobDisposition::New, 'status' => JobPostingLifecycleStatus::Closed], null);
    frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past);

    $plain = $this->get('/jobs')->viewData('page')['props'];
    $withParam = $this->get('/jobs?inactive=1')->viewData('page')['props'];

    expect(collect($plain['jobs'])->pluck('id')->all())->toBe(collect($withParam['jobs'])->pluck('id')->all())
        ->and($plain['hidden_inactive'])->toBe(0)->and($withParam['hidden_inactive'])->toBe(0)
        ->and(collect($plain['jobs'])->pluck('freshness')->filter()->all())->toBe([])
        ->and($plain['pagination']['total'])->toBe(2);
    expect(collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->pluck('freshness')->filter()->all())->toBe([])
        ->and($this->get('/')->viewData('page')['props']['overview']['jobs'])->toBe(2, 'unchanged: Home has always left out status=closed');
});

// ---- no interaction triggers a provider request, a status change, an inspection or a resume ------------------------------------------------------------

it('causes no provider request, no write, no inspection and no resume generation by viewing any freshness surface', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $pursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past);
    $expired = frJob(['disposition' => JobDisposition::New], $past);
    Application::factory()->create(['job_posting_id' => $pursued->id]);
    Http::fake();
    $counts = fn () => [AgentRun::count(), GenerationAttempt::count(), Application::count(), JobPosting::where('status', 'closed')->count(), DB::table('job_postings')->orderBy('id')->get()->toJson()];
    $before = $counts();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^(select|pragma)/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    foreach (['/jobs', '/jobs?inactive=1', '/jobs?q=x&inactive=1', '/jobs?disposition=shortlist', '/jobs?job='.$expired->id, '/jobs?status=not_inspected', '/opportunities', '/opportunities?inspect='.$pursued->id, route('opportunities.show', $pursued), route('jobs.show', $expired), '/'] as $url) {
        $this->get($url)->assertOk();
    }

    Http::assertNothingSent();
    expect($counts())->toBe($before)->and(collect($writes)->reject(fn ($q) => preg_match('/sessions|cache|jobs"? /i', $q))->all())->toBe([]);
});

it('adds no mutating call to the freshness UI: the toggle is a GET navigation and the badge is display only', function () {
    $files = ['resources/js/components/job-freshness.tsx'];
    foreach ($files as $file) {
        expect(file_get_contents(base_path($file)))->not->toMatch('/router\.(post|put|patch|delete)|fetch\(|axios|useForm|XMLHttpRequest|usePoll/');
    }
    $index = file_get_contents(base_path('resources/js/pages/jobs/index.tsx'));
    $start = strpos($index, 'const toggleInactive');
    $toggle = substr($index, $start, strpos($index, 'const applyFilters', $start) - $start);

    expect($toggle)->toContain('router.get(')->not->toMatch('/router\.(post|put|patch|delete)|fetch\(|startInspection|useStartInspection|generate|dispatch/i');
});
