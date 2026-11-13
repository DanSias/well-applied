<?php

use App\Enums\DecisionSource;
use App\Enums\DismissalReason;
use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobDecision;
use App\Models\JobPosting;
use App\Support\JobDecisions\RecordJobDecision;
use App\Support\JobDecisions\RecordJobDecisionBatch;

function httpJob(CareerProfile $profile, array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => $profile->id] + $attributes);
}

function flashed(): array
{
    return session('decision_result');
}

it('shortlists one job and flashes a result the page can undo', function () {
    $profile = CareerProfile::factory()->create();
    $job = httpJob($profile);

    $this->from('/jobs')->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted'])->assertRedirect('/jobs');

    $decision = JobDecision::firstOrFail();
    expect($job->fresh()->disposition)->toBe(JobDisposition::Shortlisted)
        ->and(flashed())->toMatchArray(['to' => 'shortlisted', 'changed' => [$job->id], 'unchanged' => [], 'failed' => []])
        ->and(flashed()['decision_ids'])->toBe([$job->id => $decision->id]);
});

it('dismisses with a reason and an optional note, and refuses a dismissal without a reason', function () {
    $profile = CareerProfile::factory()->create();
    $job = httpJob($profile);

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'dismissed'])->assertSessionHasErrors('reason');
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'dismissed', 'reason' => 'nonsense'])->assertSessionHasErrors('reason');
    expect($job->fresh()->disposition)->toBe(JobDisposition::New);

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'dismissed', 'reason' => 'technology_stack', 'note' => 'All PHP'])->assertSessionHasNoErrors();

    expect($job->fresh()->disposition_reason)->toBe('technology_stack')->and(JobDecision::first()->note)->toBe('All PHP');
});

it('pursues only when a person asks, and rejects unknown dispositions', function () {
    $job = httpJob(CareerProfile::factory()->create());

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'pursued'])->assertSessionHasNoErrors();
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'archived'])->assertSessionHasErrors('disposition');

    expect($job->fresh()->disposition)->toBe(JobDisposition::Pursued)->and($job->fresh()->pursued_at)->not->toBeNull()
        ->and(JobDecision::first()->source)->toBe(DecisionSource::User);
});

it('reports a refused move in the result instead of failing the request', function () {
    $job = httpJob($profile = CareerProfile::factory()->create());
    app(RecordJobDecision::class)->record($job, JobDisposition::Dismissed, DecisionSource::User, DismissalReason::Other);

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'pursued'])->assertSessionHasNoErrors();

    expect(flashed()['failed'])->toHaveCount(1)->and(flashed()['failed'][0]['id'])->toBe($job->id)->and($job->fresh()->disposition)->toBe(JobDisposition::Dismissed);
});

it('answers a decision for a job outside the current profile as not found, and changes nothing', function () {
    httpJob(CareerProfile::factory()->create());
    $foreign = httpJob(CareerProfile::factory()->create());

    $this->post("/jobs/{$foreign->id}/decision", ['disposition' => 'shortlisted']);

    expect(flashed()['not_found'])->toBe([$foreign->id])->and($foreign->fresh()->disposition)->toBe(JobDisposition::New);
});

it('undoes a decision through the reported decision ids, once', function () {
    $job = httpJob(CareerProfile::factory()->create());
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted']);
    $ids = flashed()['decision_ids'];

    $this->post('/jobs/decisions/undo', ['decisions' => $ids])->assertSessionHasNoErrors();
    expect($job->fresh()->disposition)->toBe(JobDisposition::New)->and(flashed()['changed'])->toBe([$job->id]);

    $this->post('/jobs/decisions/undo', ['decisions' => $ids]);
    expect(flashed()['failed'])->toHaveCount(1)->and($job->fresh()->disposition)->toBe(JobDisposition::New)->and(JobDecision::count())->toBe(2);
});

it('shortlists, dismisses and restores a selection in bulk and says what happened to each job', function () {
    $profile = CareerProfile::factory()->create();
    [$a, $b, $c] = [httpJob($profile), httpJob($profile), httpJob($profile)];
    app(RecordJobDecision::class)->record($b, JobDisposition::Shortlisted, DecisionSource::User);

    $this->post('/jobs/batch/decision', ['job_ids' => [$a->id, $b->id, $c->id, 987654], 'disposition' => 'shortlisted']);

    expect(flashed()['changed'])->toBe([$a->id, $c->id])->and(flashed()['unchanged'])->toBe([$b->id])->and(flashed()['not_found'])->toBe([987654]);

    $this->post('/jobs/batch/decision', ['job_ids' => [$a->id, $b->id], 'disposition' => 'dismissed', 'reason' => 'location', 'note' => 'Onsite']);
    expect(JobPosting::where('disposition', 'dismissed')->pluck('id')->all())->toEqualCanonicalizing([$a->id, $b->id])
        ->and(JobPosting::find($a->id)->disposition_reason)->toBe('location');

    $this->post('/jobs/batch/decision', ['job_ids' => [$a->id, $b->id], 'disposition' => 'new']);
    expect(JobPosting::where('disposition', 'new')->count())->toBe(2);
});

it('does not let a bulk request pursue, and validates the selection', function () {
    $profile = CareerProfile::factory()->create();
    $job = httpJob($profile);

    $this->post('/jobs/batch/decision', ['job_ids' => [$job->id], 'disposition' => 'pursued'])->assertSessionHasErrors('disposition');
    $this->post('/jobs/batch/decision', ['disposition' => 'shortlisted'])->assertSessionHasErrors('job_ids');
    $this->post('/jobs/batch/decision', ['job_ids' => [$job->id, $job->id], 'disposition' => 'shortlisted'])->assertSessionHasErrors('job_ids.0');
    $this->post('/jobs/batch/decision', ['job_ids' => range(1, RecordJobDecisionBatch::MAX_ITEMS + 1), 'disposition' => 'shortlisted'])->assertSessionHasErrors('job_ids');

    expect($job->fresh()->disposition)->toBe(JobDisposition::New)->and(JobDecision::count())->toBe(0);
});

it('applies a decision to more than the 50 jobs analysis allows', function () {
    $profile = CareerProfile::factory()->create();
    $ids = JobPosting::factory()->count(120)->create(['career_profile_id' => $profile->id])->pluck('id')->all();

    $this->post('/jobs/batch/decision', ['job_ids' => $ids, 'disposition' => 'shortlisted']);

    expect(count(flashed()['changed']))->toBe(120)->and(JobPosting::where('disposition', 'shortlisted')->count())->toBe(120);
});

// ---- The Jobs page: decision views, URL state, and how they combine with the other filters.

function jobsPage($test, string $query = ''): array
{
    return $test->get('/jobs'.$query)->viewData('page')['props'];
}

it('shows the inbox by default and keeps shortlisted, dismissed and pursued jobs out of it', function () {
    $profile = CareerProfile::factory()->create();
    $new = httpJob($profile);
    $short = httpJob($profile);
    $dismissed = httpJob($profile);
    $pursued = httpJob($profile);
    $service = app(RecordJobDecision::class);
    $service->record($short, JobDisposition::Shortlisted, DecisionSource::User);
    $service->record($dismissed, JobDisposition::Dismissed, DecisionSource::User, DismissalReason::Seniority);
    $service->record($pursued, JobDisposition::Pursued, DecisionSource::User);

    $ids = fn (string $q) => array_column(jobsPage($this, $q)['jobs'], 'id');

    expect($ids(''))->toBe([$new->id])
        ->and($ids('?disposition=inbox'))->toBe([$new->id])
        ->and($ids('?disposition=shortlist'))->toBe([$short->id])
        ->and($ids('?disposition=dismissed'))->toBe([$dismissed->id])
        ->and($ids('?disposition=all'))->toEqualCanonicalizing([$new->id, $short->id, $dismissed->id, $pursued->id])
        ->and($ids('?disposition=bogus'))->toBe([$new->id]);

    $props = jobsPage($this, '?disposition=all');
    $byId = collect($props['jobs'])->keyBy('id');

    expect($props['filters']['disposition'])->toBe('all')
        ->and(jobsPage($this, '?disposition=bogus')['filters']['disposition'])->toBe('inbox')
        ->and($props['disposition_counts'])->toBe(['inbox' => 1, 'shortlist' => 1, 'dismissed' => 1, 'all' => 4])
        ->and($byId[$pursued->id]['disposition']['value'])->toBe('pursued')
        ->and($byId[$dismissed->id]['disposition'])->toMatchArray(['value' => 'dismissed', 'reason' => 'seniority', 'reason_label' => 'Seniority']);
});

it('combines the decision view with search, stage, resume, inspection and paging', function () {
    $profile = CareerProfile::factory()->create();
    $service = app(RecordJobDecision::class);
    $alpha = httpJob($profile, ['title' => 'Alpha Engineer']);
    $beta = httpJob($profile, ['title' => 'Beta Engineer']);
    $gamma = httpJob($profile, ['title' => 'Alpha Designer']);
    $service->record($alpha, JobDisposition::Shortlisted, DecisionSource::User);
    $service->record($gamma, JobDisposition::Shortlisted, DecisionSource::User);
    JobAnalysis::factory()->for($alpha, 'jobPosting')->create(['generated_at' => now()]);

    $ids = fn (string $q) => array_column(jobsPage($this, $q)['jobs'], 'id');

    expect($ids('?disposition=shortlist&q=Alpha'))->toEqualCanonicalizing([$alpha->id, $gamma->id])
        ->and($ids('?disposition=shortlist&q=Designer'))->toBe([$gamma->id])
        ->and($ids('?disposition=shortlist&triage=analyzed'))->toBe([$alpha->id])
        ->and($ids('?disposition=shortlist&triage=raw'))->toBe([$gamma->id])
        ->and($ids('?disposition=shortlist&status=not_inspected&q=Alpha&triage=raw'))->toBe([$gamma->id])
        ->and($ids('?disposition=inbox&q=Alpha'))->toBe([])
        ->and($ids('?disposition=inbox'))->toBe([$beta->id])
        ->and(jobsPage($this, '?disposition=shortlist&per_page=25&page=9')['pagination']['page'])->toBe(1);
});

it('keeps existing jobs in the inbox after the migration, whatever work they already have', function () {
    // Rows written without going through Eloquent (as the migration's column default does) read back as `new`.
    $profile = CareerProfile::factory()->create();
    $id = DB::table('job_postings')->insertGetId(['career_profile_id' => $profile->id, 'company' => 'Acme', 'title' => 'Engineer', 'description' => 'x', 'created_at' => now(), 'updated_at' => now()]);

    expect(JobPosting::find($id)->disposition)->toBe(JobDisposition::New)->and(array_column(jobsPage($this)['jobs'], 'id'))->toBe([$id]);
});

// ---- Feedback delivery: a toast is built from flash the server sends once, and only when the server confirmed.

it('hands the decision result to the next page load once, so a reload or a later visit never replays it', function () {
    $job = httpJob(CareerProfile::factory()->create());
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted']);

    $first = $this->get('/jobs')->viewData('page');
    $second = $this->get('/jobs')->viewData('page');

    expect($first['flash']['decision_result'])->toMatchArray(['to' => 'shortlisted', 'changed' => [$job->id]])
        ->and($first['flash']['decision_result']['decision_ids'])->toHaveKey((string) $job->id)
        ->and($second['flash'] ?? [])->not->toHaveKey('decision_result');
});

it('sends no result, so no success message, when the request is rejected', function () {
    $job = httpJob(CareerProfile::factory()->create());

    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'dismissed'])->assertSessionHasErrors('reason');

    expect(session('decision_result'))->toBeNull()->and($job->fresh()->disposition)->toBe(JobDisposition::New);
});

it('reports an undo that no longer applies as a refusal the page can explain, never a silent overwrite', function () {
    $job = httpJob(CareerProfile::factory()->create());
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'shortlisted']);
    $stale = session('decision_result')['decision_ids'];
    $this->post("/jobs/{$job->id}/decision", ['disposition' => 'pursued']);

    $this->post('/jobs/decisions/undo', ['decisions' => $stale])->assertSessionHasNoErrors();

    $result = session('decision_result');
    expect($result['to'])->toBe('undo')->and($result['changed'])->toBe([])
        ->and($result['failed'][0]['message'])->toContain('can no longer be undone')
        ->and($job->fresh()->disposition)->toBe(JobDisposition::Pursued);
});
