<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\DismissalReason;
use App\Enums\JobDiscoverySource;
use App\Enums\JobDisposition;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\GenerationAttempt;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Support\JobFreshness\JobFreshness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ResumeVariantFixtures;

require_once __DIR__.'/PresentationTest.php';

/**
 * One effective-actionability decision, shared by the Inbox, the Shortlist, Overview, the counts and every backend guard. Only a KNOWN EXPIRY or a CONFIRMED CLOSURE (a persisted
 * closed status, a passed provider expiry, or closure confirmed by recorded observations) makes a job non-actionable; "May be closed", "Not live-checked", "Check inconclusive" and
 * "Confirmed open" stay actionable. With the feature off nothing here applies.
 */

/** @return array<string, array{0: Closure, 1: bool}> label => [make a job, actionable?] */
function csStates(): array
{
    $past = now()->subDay()->timestamp;
    $future = now()->addMonth()->timestamp;

    return [
        'unchecked' => [fn () => frJob(['disposition' => JobDisposition::New], $future), true],
        'confirmed open' => [fn () => prJob([prObs('active', 'http_200', 3)], ['disposition' => JobDisposition::New], $future), true],
        'possibly closed' => [fn () => prJob([prObs('closed', 'http_410', 1)], ['disposition' => JobDisposition::New], $future), true],
        'inconclusive' => [fn () => prJob([prObs('uncertain', 'http_404', 5), prObs('uncertain', 'network_error', 2)], ['disposition' => JobDisposition::New], $future), true],
        'expired (known expiry)' => [fn () => frJob(['disposition' => JobDisposition::New], $past), false],
        'confirmed closed (observations)' => [fn () => prJob([prObs('closed', 'http_410', 40), prObs('closed', 'http_410', 1)], ['disposition' => JobDisposition::New], $future), false],
        'closed (persisted status)' => [fn () => frJob(['disposition' => JobDisposition::New, 'status' => JobPostingLifecycleStatus::Closed], $future), false],
    ];
}

// ---- the one decision -------------------------------------------------------------------------------------------------------------------------

it('decides actionability once: only known expiry or confirmed closure is non-actionable', function (string $label) {
    frOn();
    [$make, $actionable] = csStates()[$label];
    $job = $make();

    expect(JobFreshness::actionable($job))->toBe($actionable)
        ->and(JobFreshness::newWorkBlock($job) === null)->toBe($actionable)
        ->and(JobFreshness::leftOut($job))->toBe(! $actionable)
        ->and(JobFreshness::leftOutIds(JobPosting::query()))->toBe($actionable ? [] : [$job->id])
        ->and(JobFreshness::view($job)['actionable'])->toBe($actionable)
        ->and(JobFreshness::view($job)['new_work_block'] === null)->toBe($actionable);
})->with(array_keys(csStates()));

it('never leaves out or blocks anything with the feature off', function (string $label) {
    [$make] = csStates()[$label];
    $job = $make();

    expect(JobFreshness::actionable($job))->toBeTrue()->and(JobFreshness::newWorkBlock($job))->toBeNull()->and(JobFreshness::leftOut($job))->toBeFalse()->and(JobFreshness::leftOutIds(JobPosting::query()))->toBe([])->and(JobFreshness::view($job))->toBeNull();
})->with(array_keys(csStates()));

it('protects pursued jobs and jobs with an Application from being left out, but not from the new-work block', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $pursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past);
    $applied = frJob(['disposition' => JobDisposition::New], $past);
    Application::factory()->create(['job_posting_id' => $applied->id]);

    expect(JobFreshness::leftOutIds(JobPosting::query()))->toBe([])
        ->and(JobFreshness::leftOut($pursued))->toBeFalse()->and(JobFreshness::leftOut($applied))->toBeFalse()
        ->and(JobFreshness::newWorkBlock($pursued))->toContain('expired')->and(JobFreshness::newWorkBlock($applied))->toContain('expired');
});

it('writes nothing and makes no request while deciding, listing and counting', function () {
    frOn();
    Http::fake();
    foreach (csStates() as [$make]) {
        $make();
    }
    $before = DB::table('job_postings')->orderBy('id')->get()->toJson();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    foreach (['/jobs', '/jobs?inactive=1', '/jobs?disposition=shortlist', '/', '/opportunities'] as $url) {
        $this->get($url)->assertOk();
    }

    Http::assertNothingSent();
    expect($writes)->toBe([])->and(DB::table('job_postings')->orderBy('id')->get()->toJson())->toBe($before);
});

// ---- Inbox, Shortlist, Overview and the counts ---------------------------------------------------------------------------------------------------

it('lists only the actionable jobs in the Inbox, and "Show them" brings every other one back', function () {
    frOn();
    $made = collect(csStates())->map(fn ($s) => [($s[0])(), $s[1]]);
    $actionableIds = $made->filter(fn ($m) => $m[1])->map(fn ($m) => $m[0]->id)->sort()->values()->all();
    $leftOutIds = $made->reject(fn ($m) => $m[1])->map(fn ($m) => $m[0]->id)->sort()->values()->all();

    $props = $this->get('/jobs')->viewData('page')['props'];
    expect(collect($props['jobs'])->pluck('id')->sort()->values()->all())->toBe($actionableIds)
        ->and($props['hidden_inactive'])->toBe(count($leftOutIds));

    $all = $this->get('/jobs?inactive=1')->viewData('page')['props'];
    expect(collect($all['jobs'])->pluck('id')->sort()->values()->all())->toBe(collect([...$actionableIds, ...$leftOutIds])->sort()->values()->all());
});

it('leaves a confirmed-closed job out of the Shortlist as well', function () {
    frOn();
    $closed = prJob([prObs('closed', 'http_410', 40), prObs('closed', 'http_410', 1)], ['disposition' => JobDisposition::Shortlisted], now()->addMonth()->timestamp);
    $open = frJob(['disposition' => JobDisposition::Shortlisted], now()->addMonth()->timestamp);

    $props = $this->get('/jobs?disposition=shortlist')->viewData('page')['props'];

    expect(collect($props['jobs'])->pluck('id')->all())->toBe([$open->id])->and($props['hidden_inactive'])->toBe(1)
        ->and($props['disposition_counts']['shortlist'])->toBe(1)->and($props['left_out_counts'])->toBe(['inbox' => 0, 'shortlist' => 1]);
});

it('makes the tab counts count what is actionable, with All and Dismissed staying totals', function () {
    frOn();
    foreach (csStates() as [$make]) {
        $make();
    }
    $dismissed = frJob(['disposition' => JobDisposition::Dismissed], now()->subDay()->timestamp);

    $props = $this->get('/jobs')->viewData('page')['props'];

    expect($props['disposition_counts'])->toBe(['inbox' => 4, 'shortlist' => 0, 'dismissed' => 1, 'all' => 8])
        ->and($props['left_out_counts'])->toBe(['inbox' => 3, 'shortlist' => 0])
        ->and(count($props['jobs']))->toBe($props['disposition_counts']['inbox'])
        ->and($dismissed->id)->toBeInt();
});

it('keeps the left-out count equal to what "Show them" adds, across searches and filters', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $future = now()->addMonth()->timestamp;
    frJob(['title' => 'Alpha Engineer', 'disposition' => JobDisposition::New], $future);
    frJob(['title' => 'Alpha Engineer', 'disposition' => JobDisposition::New], $past);
    prJob([prObs('closed', 'http_410', 40), prObs('closed', 'http_410', 1)], ['title' => 'Alpha Analyst', 'disposition' => JobDisposition::New], $future);
    frJob(['title' => 'Beta Designer', 'disposition' => JobDisposition::New], $past);

    foreach (['', '?q=Alpha', '?q=Analyst', '?q=Beta', '?q=nomatch', '?status=not_inspected', '?q=Alpha&status=not_inspected', '?triage=raw'] as $query) {
        $hidden = $this->get('/jobs'.$query)->viewData('page')['props'];
        $shown = $this->get('/jobs'.$query.($query === '' ? '?' : '&').'inactive=1')->viewData('page')['props'];

        expect($shown['pagination']['total'])->toBe($hidden['pagination']['total'] + $hidden['hidden_inactive'], $query);
    }
    expect($this->get('/jobs?q=Alpha')->viewData('page')['props']['hidden_inactive'])->toBe(2)
        ->and($this->get('/jobs?q=Beta')->viewData('page')['props']['hidden_inactive'])->toBe(1);
});

it('reports Overview totals and actionable counts separately, and filters them by the same decision', function () {
    frOn();
    foreach (csStates() as [$make]) {
        $make();
    }
    $pursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], now()->subDay()->timestamp);

    $o = $this->get('/')->viewData('page')['props']['overview'];

    expect($o['jobs'])->toBe(7, 'every job Home lists: expired and confirmed-closed ones included (a persisted-closed job has never been on Home)')
        ->and($o['actionable'])->toBe(5, 'the four actionable inbox jobs and the protected pursued job')
        ->and($o['left_out'])->toBe(2)
        ->and($o['awaiting'])->toBe(4)->and($o['pursued'])->toBe(1)
        ->and($pursued->id)->toBeInt();
});

// ---- protected jobs: history stays reachable ---------------------------------------------------------------------------------------------------------

it('keeps every view of a protected inactive job reachable', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $pursued = prJob([prObs('closed', 'http_410', 40), prObs('closed', 'http_410', 1)], ['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], now()->addMonth()->timestamp);
    $applied = frJob(['disposition' => JobDisposition::New], $past);
    $application = Application::factory()->create(['job_posting_id' => $applied->id]);

    $this->get(route('opportunities.show', $pursued))->assertOk()->assertInertia(fn ($page) => $page->where('opportunity.freshness.label', 'Closed'));
    $this->get(route('jobs.show', $pursued))->assertOk();
    $this->get('/opportunities?inspect='.$pursued->id)->assertOk();
    $this->get(route('applications.show', $application))->assertOk()->assertInertia(fn ($page) => $page->where('application.job_posting.freshness.label', 'Expired'));
    $this->get('/jobs?job='.$pursued->id)->assertOk();
    expect(collect($this->get('/opportunities')->viewData('page')['props']['opportunities'])->pluck('id')->all())->toContain($pursued->id)
        ->and(collect($this->get('/opportunities')->viewData('page')['props']['review'])->firstWhere('job_id', $applied->id)['freshness']['label'])->toBe('Expired')
        ->and(collect($this->get('/jobs')->viewData('page')['props']['jobs'])->pluck('id')->all())->toContain($applied->id);
});

// ---- direct backend attempts -------------------------------------------------------------------------------------------------------------------------

it('refuses to shortlist or pursue an expired or confirmed-closed job, even by a direct request, and changes nothing', function (string $label) {
    frOn();
    [$make] = csStates()[$label];
    $job = $make();

    $single = $this->post(route('jobs.decision.store', $job), ['disposition' => 'shortlisted']);
    $batch = $this->post(route('jobs.batch.decision'), ['disposition' => 'shortlisted', 'job_ids' => [$job->id]]);

    expect($job->fresh()->disposition)->toBe(JobDisposition::New)
        ->and(session('decision_result')['failed'][0]['message'])->toContain('new pursuit work');
    $this->post(route('jobs.decision.store', $job), ['disposition' => 'pursued']);
    expect($job->fresh()->disposition)->toBe(JobDisposition::New)->and(DB::table('job_decisions')->count())->toBe(0);
    expect([$single->status(), $batch->status()])->each->toBeLessThan(400);
})->with(['expired (known expiry)', 'confirmed closed (observations)', 'closed (persisted status)']);

it('still lets an actionable job be shortlisted and pursued: unchecked, confirmed open, possibly closed, inconclusive', function (string $label) {
    frOn();
    [$make] = csStates()[$label];
    $job = $make();

    $this->post(route('jobs.decision.store', $job), ['disposition' => 'shortlisted']);
    expect($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted);
    $this->post(route('jobs.decision.store', $job), ['disposition' => 'pursued']);
    expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
})->with(['unchecked', 'confirmed open', 'possibly closed', 'inconclusive']);

it('still lets an expired job be dismissed, restored, and a pursued expired job stop being pursued', function () {
    frOn();
    $past = now()->subDay()->timestamp;
    $inbox = frJob(['disposition' => JobDisposition::New], $past);
    $pursued = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now()], $past);

    $this->post(route('jobs.decision.store', $inbox), ['disposition' => 'dismissed', 'reason' => DismissalReason::Other->value]);
    expect($inbox->fresh()->disposition)->toBe(JobDisposition::Dismissed);
    $this->post(route('jobs.decision.store', $inbox), ['disposition' => 'new']);
    expect($inbox->fresh()->disposition)->toBe(JobDisposition::New);

    $this->post(route('opportunities.stop', $pursued), ['reason' => DismissalReason::NoLongerInterested->value]);
    expect($pursued->fresh()->disposition)->toBe(JobDisposition::Dismissed);
});

it('lets undo restore an earlier shortlist: it is not a new pursuit', function () {
    config(['job_freshness.enabled' => false]);
    $job = frJob(['disposition' => JobDisposition::Shortlisted], now()->addDay()->timestamp);
    $this->post(route('jobs.decision.store', $job), ['disposition' => 'dismissed', 'reason' => DismissalReason::Other->value]);
    $decisionId = session('decision_result')['decision_ids'][$job->id];
    $job->update(['discovery_metadata' => ['discovery' => ['expiryDate' => now()->subDay()->timestamp, 'companySlug' => 'acme']]]);
    frOn();

    $this->post(route('jobs.decisions.undo'), ['decisions' => [$job->id => $decisionId]]);

    expect($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted);
});

it('refuses a new inspection, a new tailored resume, a new resume variant and a preparation on an inactive job, and starts nothing', function (string $label) {
    frOn();
    Queue::fake();
    [$make] = csStates()[$label];
    $job = $make();
    $job->forceFill(['source_url' => 'https://example.com/apply', 'disposition' => JobDisposition::Pursued, 'pursued_at' => now()])->save();

    $this->post(route('jobs.applications.store', $job))->assertStatus(422);
    $this->post(route('jobs.tailored-resume.store', $job))->assertStatus(422);
    $this->postJson(route('jobs.preparations.store', $job))->assertStatus(422)->assertJsonPath('reason', 'job_inactive');

    expect(Application::count())->toBe(0)->and(WorkflowRun::count())->toBe(0)->and(AgentRun::count())->toBe(0)->and(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['expired (known expiry)', 'confirmed closed (observations)', 'closed (persisted status)']);

it('refuses a new resume variant on an inactive job', function () {
    frOn();
    Queue::fake();
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch($candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'], $candidate['factAws'], $candidate['factIndependent']);
    $posting = $match->jobAnalysis->jobPosting;
    $posting->update(['discovery_source' => JobDiscoverySource::Himalayas, 'discovery_metadata' => ['discovery' => ['expiryDate' => now()->subDay()->timestamp]]]);

    $this->post(route('jobs.analyses.matches.resume.store', [$posting, $match->jobAnalysis, $match]))->assertStatus(422);

    expect(GenerationAttempt::count())->toBe(0)->and(JobMatch::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('still lets an actionable job start an inspection and a tailored resume', function (string $label) {
    frOn();
    Queue::fake();
    [$make] = csStates()[$label];
    $job = $make();
    $job->forceFill(['source_url' => 'https://example.com/apply', 'disposition' => JobDisposition::Pursued, 'pursued_at' => now(), 'description_completeness' => DescriptionCompleteness::Complete])->save();

    $this->post(route('jobs.applications.store', $job), ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $job));
    $this->post(route('jobs.tailored-resume.store', $job), ['return_to' => 'opportunity'])->assertRedirect(route('opportunities.show', $job));

    expect(Application::count())->toBe(1);
})->with(['unchecked', 'confirmed open', 'possibly closed', 'inconclusive']);

it('leaves existing history intact when a job turns inactive: the application and its results stay readable', function () {
    frOn();
    $job = frJob(['disposition' => JobDisposition::Pursued, 'pursued_at' => now(), 'source_url' => 'https://example.com/apply'], now()->subDay()->timestamp);
    $application = Application::factory()->create(['job_posting_id' => $job->id]);

    $this->get(route('applications.show', $application))->assertOk();
    $this->get(route('applications.status', $application))->assertOk();
    $this->get(route('opportunities.show', $job))->assertOk();
});

// ---- the feature off: everything as before -------------------------------------------------------------------------------------------------------------

it('behaves exactly as before with the feature off: no payload keys, no refusals, nothing left out', function () {
    foreach (csStates() as [$make]) {
        $make();
    }
    $expired = frJob(['disposition' => JobDisposition::New, 'source_url' => 'https://example.com/apply', 'description_completeness' => DescriptionCompleteness::Complete], now()->subDay()->timestamp);

    $jobs = $this->get('/jobs')->viewData('page')['props'];
    $home = $this->get('/')->viewData('page')['props']['overview'];

    expect(count($jobs['jobs']))->toBe(8)->and($jobs['hidden_inactive'])->toBe(0)->and(array_key_exists('left_out_counts', $jobs))->toBeFalse()
        ->and($jobs['disposition_counts'])->toBe(['inbox' => 8, 'shortlist' => 0, 'dismissed' => 0, 'all' => 8])
        ->and(array_key_exists('actionable', $home))->toBeFalse()->and(array_key_exists('left_out', $home))->toBeFalse()->and($home['jobs'])->toBe(7);

    $this->post(route('jobs.decision.store', $expired), ['disposition' => 'shortlisted']);
    expect($expired->fresh()->disposition)->toBe(JobDisposition::Shortlisted);
    $this->post(route('jobs.decision.store', $expired), ['disposition' => 'pursued']);
    expect($expired->fresh()->disposition)->toBe(JobDisposition::Pursued);
    Queue::fake();
    $this->post(route('jobs.applications.store', $expired))->assertRedirect();
    $this->post(route('jobs.tailored-resume.store', $expired))->assertRedirect();

    $application = Application::first();
    $this->get(route('applications.show', $application))->assertOk()->assertInertia(fn ($page) => $page->missing('application.job_posting.freshness'));
    expect(array_key_exists('freshness', $this->get('/opportunities')->viewData('page')['props']['opportunities'][0] ?? ['freshness' => null]))->toBeTrue();
});
