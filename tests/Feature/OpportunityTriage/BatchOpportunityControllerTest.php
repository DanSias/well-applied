<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobAnalysisJob;
use App\Jobs\GenerateJobMatchJob;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Support\GenerationAttempt\QueueGenerationAttempt;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * Phase E3 — HTTP-level coverage of the human-initiated batch endpoints:
 *
 *   POST /jobs/batch/analyze  (jobs.batch.analyze)
 *   POST /jobs/batch/match    (jobs.batch.match)
 *
 * Both accept an explicit `job_ids` list (1–50 distinct integers), resolve
 * every id against the current career profile only (foreign and
 * nonexistent ids are indistinguishable `not_found` results), decide each
 * item from the shared triage summary (one SummarizeTriageStates call per
 * batch), and claim any started work through QueueGenerationAttempt. The
 * structured result is flashed under `batch_result`. All helper names are
 * `batch*`-prefixed: Pest concatenates every Feature file into one global
 * namespace, so they must not collide with SummarizeTriageStatesTest's
 * helpers (triage/careerProfile/postingFor/analysisFor/matchFor/attemptFor).
 *
 * Deliberately no timing/sleep tests: in-flight detection is covered
 * structurally via held claim locks and seed-stage fixtures, the same way
 * QueueGenerationAttemptTest and SummarizeTriageStatesTest do it.
 */
function batchProfile(): CareerProfile
{
    return CareerProfile::factory()->create();
}

function batchPosting(CareerProfile $profile, array $attributes = []): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => $profile->id] + $attributes);
}

function batchAnalysis(JobPosting $posting, ?string $generatedAt = null): JobAnalysis
{
    return JobAnalysis::factory()
        ->for($posting, 'jobPosting')
        ->create($generatedAt === null ? [] : ['generated_at' => $generatedAt]);
}

function batchMatch(CareerProfile $profile, JobAnalysis $analysis): JobMatch
{
    // Override BOTH foreign keys: without an explicit career_profile_id
    // the factory would auto-create an extra profile, which is harmless
    // for lowest-id resolution only because it would be created later.
    return JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'generated_at' => now(),
    ]);
}

function batchAttempt(Model $subject, GenerationType $type, GenerationStatus $status): GenerationAttempt
{
    // getMorphClass() writes the enforced morph-map alias, matching what
    // SummarizeTriageStates's whereMorphedTo('subject', …) queries back.
    return GenerationAttempt::factory()->create([
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->id,
        'generation_type' => $type,
        'status' => $status,
    ]);
}

/**
 * Assert the full batch result contract: redirect back to /jobs, the
 * `batch_result` flash, and its exact shape — top-level key order
 * (operation, requested, started, skipped, failed, not_found, items),
 * per-item key order (job_id, outcome, reason), requested-order items,
 * int job ids, null reason for started/not_found, and counts that sum to
 * requested. The array session driver stores the value unserialized, so
 * the strict toBe above is meaningful (order + type sensitive).
 *
 * @param  list<array{job_id: int, outcome: string, reason: string|null}>  $items
 */
function assertBatchResult(TestResponse $response, string $operation, array $items): void
{
    $expected = [
        'operation' => $operation,
        'requested' => count($items),
        'started' => count(array_filter($items, fn (array $i) => $i['outcome'] === 'started')),
        'skipped' => count(array_filter($items, fn (array $i) => $i['outcome'] === 'skipped')),
        'failed' => count(array_filter($items, fn (array $i) => $i['outcome'] === 'failed')),
        'not_found' => count(array_filter($items, fn (array $i) => $i['outcome'] === 'not_found')),
        'items' => array_map(
            fn (array $i) => ['job_id' => (int) $i['job_id'], 'outcome' => $i['outcome'], 'reason' => $i['reason']],
            array_values($items),
        ),
    ];

    $response->assertStatus(302);
    $response->assertRedirect('/jobs');
    $response->assertSessionHas('batch_result');

    expect(app('session.store')->get('batch_result'))->toBe($expected);
}

/*
|--------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------
*/

it('rejects a batch analyze request with no job_ids at all', function () {
    Queue::fake();

    $this->post(route('jobs.batch.analyze'), [], ['Referer' => '/jobs'])
        ->assertSessionHasErrors('job_ids');

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a batch match request whose job_ids is not an array', function () {
    Queue::fake();

    $this->post(route('jobs.batch.match'), ['job_ids' => '123'], ['Referer' => '/jobs'])
        ->assertSessionHasErrors('job_ids');

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects an empty job_ids array (min:1)', function () {
    Queue::fake();

    $this->post(route('jobs.batch.analyze'), ['job_ids' => []], ['Referer' => '/jobs'])
        ->assertSessionHasErrors('job_ids');

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a job_ids list with more than 50 ids (max:50)', function () {
    Queue::fake();

    $this->post(route('jobs.batch.match'), ['job_ids' => range(1, 51)], ['Referer' => '/jobs'])
        ->assertSessionHasErrors('job_ids');

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a non-integer job id with an error on that element', function () {
    Queue::fake();

    $this->post(route('jobs.batch.analyze'), ['job_ids' => ['abc']], ['Referer' => '/jobs'])
        ->assertSessionHasErrors('job_ids.0');

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects duplicate job ids with a distinct-ids error', function () {
    Queue::fake();

    $this->post(route('jobs.batch.match'), ['job_ids' => [1, 2, 2]], ['Referer' => '/jobs'])
        ->assertSessionHasErrors(['job_ids' => 'Job IDs must be unique.']);

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

/*
| (E3-1 regression) Validation must fail as the caller expects, for every
| input shape the endpoint can actually receive: shape errors must not
| 500, and a duplicate that only differs in representation must not slip
| through "distinctness" while the integer rule accepts it. Everything
| below goes through the real HTTP endpoint — a bare Validator call would
| not exercise the request hook or the response pipeline.
*/

it('fails job_ids containing a nested array with a per-element validation error instead of a 500', function () {
    Queue::fake();

    // `1` passes, `['x' => 'y']` fails the per-element integer rule. The
    // distinctness `after` hook must not touch that element afterwards —
    // comparing an array value under array_unique() would throw
    // "Array to string conversion" (a 500) instead of letting this clean
    // 422 land.
    $response = $this->post(
        route('jobs.batch.analyze'),
        ['job_ids' => [1, ['x' => 'y']]],
        ['Referer' => '/jobs'],
    );

    // A per-element error on the nested value — a clean validation failure,
    // not the "Array to string conversion" 500 the hook used to produce.
    $response->assertSessionHasErrors('job_ids.1');

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('treats job ids that only differ in integer representation as duplicates', function () {
    $profile = batchProfile();
    $existing = batchPosting($profile, ['description_completeness' => 'complete']);

    // `1` and `'+1'` are both integer-valid to the validator (both pass
    // FILTER_VALIDATE_INT), so a raw-string uniqueness check would miss
    // them — the endpoint must reject the pair instead of processing the
    // real id twice (which would claim the same row twice).
    $duplicateId = (int) $existing->id;

    Queue::fake();

    $response = $this->post(
        route('jobs.batch.analyze'),
        ['job_ids' => [$duplicateId, '+'.$duplicateId]],
        ['Referer' => '/jobs'],
    );

    // A whole-list uniqueness error (the custom message) — NOT per-element
    // integer failures on job_ids.0 / job_ids.1, which this request must
    // not produce: both representations are valid integers.
    $response->assertSessionHasErrors(['job_ids' => 'Job IDs must be unique.']);

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('treats whitespace-padded integer ids as duplicates too', function () {
    batchProfile(); // any current profile is enough — nothing should resolve

    Queue::fake();

    // Both `'3 '` and `'3'` pass the integer rule in this run
    // environment (FILTER_VALIDATE_INT trims), normalize to the same int,
    // and must be rejected as a duplicate — not silently deduped into a
    // single successful resolve.
    $response = $this->post(
        route('jobs.batch.match'),
        ['job_ids' => ['3 ', '3']],
        ['Referer' => '/jobs'],
    );

    $response->assertSessionHasErrors(['job_ids' => 'Job IDs must be unique.']);

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts distinct ids and does not mis-report them as duplicates', function () {
    Queue::fake();

    batchProfile(); // resolve() needs a current profile; no postings

    // Control: three genuinely distinct ids (one of them only
    // "padded" relative to the others) must pass the distinctness check
    // and fall through to not_found — the check must not over-reject.
    $response = $this->post(
        route('jobs.batch.analyze'),
        ['job_ids' => [1, 2, '3 ']],
        ['Referer' => '/jobs'],
    );

    assertBatchResult($response, 'analyze', [
        ['job_id' => 1, 'outcome' => 'not_found', 'reason' => null],
        ['job_id' => 2, 'outcome' => 'not_found', 'reason' => null],
        ['job_id' => 3, 'outcome' => 'not_found', 'reason' => null],
    ]);

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts exactly 50 ids and reports every unknown id as not_found without any generation work', function () {
    Queue::fake();

    batchProfile(); // resolve() needs a current profile, even with no postings

    $ids = range(1, 50);
    $items = array_map(
        fn (int $id) => ['job_id' => $id, 'outcome' => 'not_found', 'reason' => null],
        $ids,
    );

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => $ids], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', $items);

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------
| Analyze
|--------------------------------------------------------------------
*/

it('starts analysis for a raw posting with a complete description', function () {
    Queue::fake();

    $posting = batchPosting(batchProfile(), ['description_completeness' => 'complete']);

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($attempt->subject?->is($posting))->toBeTrue();

    Queue::assertPushed(GenerateJobAnalysisJob::class, fn (GenerateJobAnalysisJob $job) => $job->attempt->is($attempt));
});

it('flashes the batch result to Inertia flash data alongside the session flash', function () {
    Queue::fake();

    $profile = batchProfile();
    $started = batchPosting($profile, ['description_completeness' => 'complete']);

    // Second requested item: already in flight → `skipped`/`in_flight`,
    // so this case also exercises a non-null reason in the flash payload.
    $inFlight = batchPosting($profile);
    batchAttempt($inFlight, GenerationType::JobAnalysis, GenerationStatus::Queued);

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$started->id, $inFlight->id]], ['Referer' => '/jobs']);

    // Classic session-flash channel — same payload, strict equality.
    assertBatchResult($response, 'analyze', [
        ['job_id' => $started->id, 'outcome' => 'started', 'reason' => null],
        ['job_id' => $inFlight->id, 'outcome' => 'skipped', 'reason' => 'in_flight'],
    ]);

    // Inertia v3 flash channel: assertInertiaFlash with an expected value
    // delegates to AssertableInertia::assertFlashHas — assertSame, so key
    // order and types above matter (int job ids, string|null reasons).
    $response->assertInertiaFlash('batch_result', [
        'operation' => 'analyze',
        'requested' => 2,
        'started' => 1,
        'skipped' => 1,
        'failed' => 0,
        'not_found' => 0,
        'items' => [
            ['job_id' => $started->id, 'outcome' => 'started', 'reason' => null],
            ['job_id' => $inFlight->id, 'outcome' => 'skipped', 'reason' => 'in_flight'],
        ],
    ]);
});

it('starts analysis for an analysis-failed posting with a complete description', function () {
    Queue::fake();

    $profile = batchProfile();
    $posting = batchPosting($profile, ['description_completeness' => 'complete']);

    // Seed the stage: a Failed analysis attempt on the posting, no
    // snapshot — SummarizeTriageStates derives 'analysis-failed'.
    batchAttempt($posting, GenerationType::JobAnalysis, GenerationStatus::Failed);

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    // The pre-seeded Failed row stays; exactly one new Queued claim exists.
    $attempts = GenerationAttempt::query()->get();

    expect($attempts)->toHaveCount(2);

    $new = $attempts->first(function (GenerationAttempt $attempt): bool {
        return $attempt->status === GenerationStatus::Queued;
    });

    expect($new->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($new->subject?->is($posting))->toBeTrue();

    Queue::assertPushed(GenerateJobAnalysisJob::class, fn (GenerateJobAnalysisJob $job) => $job->attempt->is($new));
});

it('skips a raw posting with an unknown description completeness', function () {
    // The JobPosting creating hook defaults description_completeness to
    // 'unknown' — not explicitly 'complete' — so the batch must skip it.
    $posting = batchPosting(batchProfile());

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $posting->id, 'outcome' => 'skipped', 'reason' => 'incomplete_description'],
    ]);

    expect(GenerationAttempt::count())->toBe(0);
});

it('skips a raw posting whose description is only a preview', function () {
    $posting = batchPosting(batchProfile(), ['description_completeness' => 'preview']);

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $posting->id, 'outcome' => 'skipped', 'reason' => 'incomplete_description'],
    ]);

    expect(GenerationAttempt::count())->toBe(0);
});

it('skips an analyzing posting as in_flight', function () {
    $profile = batchProfile();
    $posting = batchPosting($profile, ['description_completeness' => 'complete']);

    // Seed the stage: a still-queued analysis attempt, no snapshot yet.
    batchAttempt($posting, GenerationType::JobAnalysis, GenerationStatus::Queued);

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $posting->id, 'outcome' => 'skipped', 'reason' => 'in_flight'],
    ]);

    // The seeded Queued row is the only row — the batch added nothing.
    expect(GenerationAttempt::count())->toBe(1);
});

it('skips analyzed, matching, match-failed and matched postings as already_analyzed', function () {
    Queue::fake();

    $profile = batchProfile();

    // analyzed — snapshot only
    $analyzedPost = batchPosting($profile);
    batchAnalysis($analyzedPost);

    // matching — snapshot + active match attempt
    $matchingPost = batchPosting($profile);
    $matchingAnalysis = batchAnalysis($matchingPost);
    batchAttempt($matchingAnalysis, GenerationType::JobMatch, GenerationStatus::Queued);

    // match-failed — snapshot + failed match attempt, no match
    $matchFailedPost = batchPosting($profile);
    $matchFailedAnalysis = batchAnalysis($matchFailedPost);
    batchAttempt($matchFailedAnalysis, GenerationType::JobMatch, GenerationStatus::Failed);

    // matched — snapshot + completed match
    $matchedPost = batchPosting($profile);
    $matchedAnalysis = batchAnalysis($matchedPost);
    batchMatch($profile, $matchedAnalysis);

    $ids = [$analyzedPost->id, $matchingPost->id, $matchFailedPost->id, $matchedPost->id];

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => $ids], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', array_map(
        fn (int $id) => ['job_id' => $id, 'outcome' => 'skipped', 'reason' => 'already_analyzed'],
        $ids,
    ));

    // Exactly the two seeded match attempts remain — none new.
    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertNothingPushed();
});

it('skips a raw posting as in_flight while its claim lock is held', function () {
    $profile = batchProfile();
    $posting = batchPosting($profile, ['description_completeness' => 'complete']);

    $held = Cache::lock(QueueGenerationAttempt::claimLockKey(GenerationType::JobAnalysis, $posting), 10);

    expect($held->acquire())->toBeTrue();

    try {
        $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

        assertBatchResult($response, 'analyze', [
            ['job_id' => $posting->id, 'outcome' => 'skipped', 'reason' => 'in_flight'],
        ]);

        expect(GenerationAttempt::count())->toBe(0);
    } finally {
        $held->release();
    }
});

it('marks a posting failed (claim_failed) when the synchronous dispatch throws, and keeps the raw exception out of the flash', function () {
    $profile = batchProfile();
    $posting = batchPosting($profile, ['description_completeness' => 'complete']);

    // The real dispatch seam throws: the concrete bus dispatcher is bound
    // under its contract with a queue resolver that throws. Deliberately
    // NOT Queue::fake() — the failure must come from the real path, which
    // is what claim() then records on the attempt row.
    app()->instance(
        BusDispatcherContract::class,
        new BusDispatcher(app(), function () {
            throw new RuntimeException('Simulated batch dispatch failure.');
        }),
    );

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $posting->id, 'outcome' => 'failed', 'reason' => 'claim_failed'],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Failed)
        ->and($attempt->failure_category)->toBe('dispatch_error')
        ->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($attempt->subject?->is($posting))->toBeTrue();

    expect(json_encode(app('session.store')->get('batch_result')))->not->toContain('Simulated batch dispatch failure.');
});

it('processes the whole batch when only the first dispatch fails (one claim_failed, one started)', function () {
    Queue::fake();

    $profile = batchProfile();

    $firstPost = batchPosting($profile, ['description_completeness' => 'complete']);
    $secondPost = batchPosting($profile, ['description_completeness' => 'complete']);

    // (E3-1, M3) A stateful dispatch seam: the FIRST dispatch call — the
    // first item's claim — throws, the SECOND succeeds and hands the job
    // to the QueueFake. The existing always-throwing resolvers cannot
    // prove this; they would leave both items failed. Only a throw-once,
    // succeed-once seam isolates exactly one item's dispatch failure out
    // of the batch and keeps the batch running.
    $dispatches = 0;

    app()->instance(
        BusDispatcherContract::class,
        new BusDispatcher(app(), function () use (&$dispatches) {
            $dispatches++;

            if ($dispatches === 1) {
                throw new RuntimeException('Simulated first-item dispatch failure.');
            }

            return app(QueueFactoryContract::class)->connection();
        }),
    );

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$firstPost->id, $secondPost->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $firstPost->id, 'outcome' => 'failed', 'reason' => 'claim_failed'],
        ['job_id' => $secondPost->id, 'outcome' => 'started', 'reason' => null],
    ]);

    $attempts = GenerationAttempt::query()->orderBy('id')->get(['id', 'status', 'generation_type', 'subject_type', 'subject_id', 'failure_category', 'failure_message', 'finished_at']);

    expect($attempts)->toHaveCount(2);

    $failed = $attempts[0];
    $started = $attempts[1];

    expect($failed->subject?->is($firstPost))->toBeTrue()
        ->and($failed->status)->toBe(GenerationStatus::Failed)
        ->and($failed->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($failed->failure_category)->toBe('dispatch_error')
        ->and($failed->failure_message)->toBe('Generation dispatch failed: Simulated first-item dispatch failure.')
        ->and($failed->finished_at)->not->toBeNull();

    expect($started->subject?->is($secondPost))->toBeTrue()
        ->and($started->status)->toBe(GenerationStatus::Queued)
        ->and($started->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($started->failure_category)->toBeNull()
        ->and($started->finished_at)->toBeNull();

    // Exactly one job — the second item's — reached a queue.
    Queue::assertPushed(GenerateJobAnalysisJob::class, fn (GenerateJobAnalysisJob $job) => $job->attempt->id === $started->id);
    Queue::assertCount(1); // only the second item's job reached a queue

    // And the raw exception message stays out of the flash exactly as in
    // the single-item claim_failed case.
    expect(json_encode(app('session.store')->get('batch_result')))->not->toContain('Simulated first-item dispatch failure.');
});

/*
|--------------------------------------------------------------------
| Match
|--------------------------------------------------------------------
*/

it('skips raw and analysis-failed postings as no_analysis', function () {
    $profile = batchProfile();

    $rawPost = batchPosting($profile);
    $failedPost = batchPosting($profile);
    batchAttempt($failedPost, GenerationType::JobAnalysis, GenerationStatus::Failed);

    $ids = [$rawPost->id, $failedPost->id];

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => $ids], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', array_map(
        fn (int $id) => ['job_id' => $id, 'outcome' => 'skipped', 'reason' => 'no_analysis'],
        $ids,
    ));

    // Only the seeded Failed attempt — the batch added nothing.
    expect(GenerationAttempt::count())->toBe(1);
});

it('skips analyzing and matching postings as in_flight', function () {
    $profile = batchProfile();

    $analyzingPost = batchPosting($profile, ['description_completeness' => 'complete']);
    batchAttempt($analyzingPost, GenerationType::JobAnalysis, GenerationStatus::Queued);

    $matchingPost = batchPosting($profile);
    $matchingAnalysis = batchAnalysis($matchingPost);
    batchAttempt($matchingAnalysis, GenerationType::JobMatch, GenerationStatus::Queued);

    $ids = [$analyzingPost->id, $matchingPost->id];

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => $ids], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', array_map(
        fn (int $id) => ['job_id' => $id, 'outcome' => 'skipped', 'reason' => 'in_flight'],
        $ids,
    ));

    expect(GenerationAttempt::count())->toBe(2);
});

it('skips a matched posting as already_matched', function () {
    $profile = batchProfile();
    $posting = batchPosting($profile);
    $analysis = batchAnalysis($posting);
    batchMatch($profile, $analysis);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'skipped', 'reason' => 'already_matched'],
    ]);

    expect(GenerationAttempt::count())->toBe(0);
});

it('starts a match for an analyzed posting, claiming against the triage-selected analysis', function () {
    Queue::fake();

    $profile = batchProfile();
    $posting = batchPosting($profile);
    $analysis = batchAnalysis($posting);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->generation_type)->toBe(GenerationType::JobMatch)
        ->and($attempt->subject?->is($analysis))->toBeTrue();

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $job) => $job->attempt->is($attempt));
});

it('starts a match for a match-failed posting', function () {
    Queue::fake();

    $profile = batchProfile();
    $posting = batchPosting($profile);
    $analysis = batchAnalysis($posting);

    batchAttempt($analysis, GenerationType::JobMatch, GenerationStatus::Failed);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    // The seeded Failed row remains; exactly one new Queued claim exists.
    $attempts = GenerationAttempt::query()->get();

    expect($attempts)->toHaveCount(2);

    $new = $attempts->first(function (GenerationAttempt $attempt): bool {
        return $attempt->status === GenerationStatus::Queued;
    });

    expect($new->generation_type)->toBe(GenerationType::JobMatch)
        ->and($new->subject?->is($analysis))->toBeTrue();

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $job) => $job->attempt->is($new));
});

it('claims against the newer analysis even when it has the lower id', function () {
    Queue::fake();

    $profile = batchProfile();
    $posting = batchPosting($profile);

    // Deliberately create the NEWER snapshot first so it gets the lower id,
    // then the older one: if the selection were "highest id wins" it would
    // pick 2026-01-01 and this test would fail. The claim must land on the
    // 2026-01-05 row, proving generated_at — not row order — decides.
    $newer = batchAnalysis($posting, '2026-01-05');
    $older = batchAnalysis($posting, '2026-01-01');

    expect($newer->id)->toBeLessThan($older->id);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->generation_type)->toBe(GenerationType::JobMatch)
        ->and($attempt->subject?->is($newer))->toBeTrue()
        ->and($attempt->subject?->is($older))->toBeFalse();

    expect(GenerationAttempt::query()->where('subject_id', $older->id)->exists())->toBeFalse();

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $job) => $job->attempt->is($attempt));
});

it('breaks a generated_at tie in favour of the higher analysis id', function () {
    Queue::fake();

    $profile = batchProfile();
    $posting = batchPosting($profile);

    // Equal generated_at: the documented tiebreak is id DESC — the higher
    // id must win deterministically, and nothing may be claimed against
    // the lower-id (first-created) row.
    $first = batchAnalysis($posting, '2026-01-03');
    $second = batchAnalysis($posting, '2026-01-03');

    expect($second->id)->toBeGreaterThan($first->id);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->generation_type)->toBe(GenerationType::JobMatch)
        ->and($attempt->subject?->is($second))->toBeTrue()
        ->and($attempt->subject?->is($first))->toBeFalse();

    expect(GenerationAttempt::query()->where('subject_id', $first->id)->exists())->toBeFalse();

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $job) => $job->attempt->is($attempt));
});

it('skips an analyzed posting as in_flight while its match claim lock is held on the selected analysis', function () {
    $profile = batchProfile();
    $posting = batchPosting($profile);
    $analysis = batchAnalysis($posting);

    $held = Cache::lock(QueueGenerationAttempt::claimLockKey(GenerationType::JobMatch, $analysis), 10);

    expect($held->acquire())->toBeTrue();

    try {
        $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

        assertBatchResult($response, 'match', [
            ['job_id' => $posting->id, 'outcome' => 'skipped', 'reason' => 'in_flight'],
        ]);

        expect(GenerationAttempt::count())->toBe(0);
    } finally {
        $held->release();
    }
});

it('marks a match failed (claim_failed) when the synchronous dispatch throws, and keeps the raw exception out of the flash', function () {
    $profile = batchProfile();
    $posting = batchPosting($profile);
    $analysis = batchAnalysis($posting);

    app()->instance(
        BusDispatcherContract::class,
        new BusDispatcher(app(), function () {
            throw new RuntimeException('Simulated batch match dispatch failure.');
        }),
    );

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'failed', 'reason' => 'claim_failed'],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Failed)
        ->and($attempt->failure_category)->toBe('dispatch_error')
        ->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->generation_type)->toBe(GenerationType::JobMatch)
        ->and($attempt->subject?->is($analysis))->toBeTrue();

    expect(json_encode(app('session.store')->get('batch_result')))->not->toContain('Simulated batch match dispatch failure.');
});

/*
|--------------------------------------------------------------------
| Profile isolation and batch result
|--------------------------------------------------------------------
*/

it('reports a foreign-profile id and a nonexistent id as indistinguishable not_found results', function () {
    batchProfile(); // current profile (lowest id) so resolve() works
    $foreignProfile = batchProfile(); // created later → never resolved as current

    // Deliberately complete and startable in isolation: under another
    // profile it must still produce not_found and zero work.
    $foreignPost = batchPosting($foreignProfile, ['description_completeness' => 'complete']);

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$foreignPost->id, 999999]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $foreignPost->id, 'outcome' => 'not_found', 'reason' => null],
        ['job_id' => 999999, 'outcome' => 'not_found', 'reason' => null],
    ]);

    // Identical shape for both — only the echoed job id differs — and
    // neither produced any generation work.
    expect(GenerationAttempt::count())->toBe(0);
});

it('reports a foreign-profile posting with an eligible analysis as not_found with no work at all', function () {
    Queue::fake();

    batchProfile(); // current profile (lowest id) so resolve() works
    $foreignProfile = batchProfile(); // created later → never resolved as current

    // (E3-1, L2) Match-side isolation: the foreign posting is complete AND
    // carries an analyzed snapshot — everything a started match needs — but
    // under a foreign career profile. If triage or the batch ever reached
    // across profiles, this would become a started claim instead of a
    // not_found result.
    $foreignPost = batchPosting($foreignProfile, ['description_completeness' => 'complete']);
    batchAnalysis($foreignPost);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$foreignPost->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $foreignPost->id, 'outcome' => 'not_found', 'reason' => null],
    ]);

    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('still starts a match when the only JobMatch on the selected analysis belongs to another profile', function () {
    Queue::fake();

    $current = batchProfile(); // lowest id → the resolved current profile
    $foreignProfile = batchProfile(); // created later → never resolved as current

    // The inverse of the test above: the posting and its analysis are the
    // CURRENT profile's, and a completed JobMatch exists on exactly this
    // analysis — but scoped to a foreign profile. Triage must ignore it
    // (matches are profile-scoped), so the stage stays `analyzed` — not
    // `matched` — and the match still starts against the same analysis.
    $posting = batchPosting($current);
    $analysis = batchAnalysis($posting);
    batchMatch($foreignProfile, $analysis);

    $response = $this->post(route('jobs.batch.match'), ['job_ids' => [$posting->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'match', [
        ['job_id' => $posting->id, 'outcome' => 'started', 'reason' => null],
    ]);

    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->generation_type)->toBe(GenerationType::JobMatch)
        ->and($attempt->subject?->is($analysis))->toBeTrue();

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $job) => $job->attempt->is($attempt));
});

it('preserves the requested id order in the result items', function () {
    $profile = batchProfile();

    $p1 = batchPosting($profile); // all three default to unknown completeness
    $p2 = batchPosting($profile);
    $p3 = batchPosting($profile);

    $requested = [$p3->id, $p1->id, $p2->id]; // deliberately not creation order

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => $requested], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', array_map(
        fn (int $id) => ['job_id' => $id, 'outcome' => 'skipped', 'reason' => 'incomplete_description'],
        $requested,
    ));

    expect(GenerationAttempt::count())->toBe(0);
});

it('processes a mixed batch and only the started item creates generation work', function () {
    Queue::fake();

    $current = batchProfile();
    $foreignProfile = batchProfile();

    $startable = batchPosting($current, ['description_completeness' => 'complete']);
    $incomplete = batchPosting($current);
    $foreignPost = batchPosting($foreignProfile, ['description_completeness' => 'complete']);

    $requested = [$incomplete->id, $startable->id, $foreignPost->id];

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => $requested], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $incomplete->id, 'outcome' => 'skipped', 'reason' => 'incomplete_description'],
        ['job_id' => $startable->id, 'outcome' => 'started', 'reason' => null],
        ['job_id' => $foreignPost->id, 'outcome' => 'not_found', 'reason' => null],
    ]);

    // The skipped and not_found items produced nothing; the started item's
    // claim is the sole attempt row.
    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($attempt->subject?->is($startable))->toBeTrue();

    Queue::assertPushed(GenerateJobAnalysisJob::class, fn (GenerateJobAnalysisJob $job) => $job->attempt->is($attempt));
});

it('lets a failed-claim item coexist with a not_found item in one batch', function () {
    $current = batchProfile();
    $foreignProfile = batchProfile();

    $post = batchPosting($current, ['description_completeness' => 'complete']);
    $foreignPost = batchPosting($foreignProfile, ['description_completeness' => 'complete']);

    app()->instance(
        BusDispatcherContract::class,
        new BusDispatcher(app(), function () {
            throw new RuntimeException('Simulated batch mixed dispatch failure.');
        }),
    );

    $response = $this->post(route('jobs.batch.analyze'), ['job_ids' => [$post->id, $foreignPost->id]], ['Referer' => '/jobs']);

    assertBatchResult($response, 'analyze', [
        ['job_id' => $post->id, 'outcome' => 'failed', 'reason' => 'claim_failed'],
        ['job_id' => $foreignPost->id, 'outcome' => 'not_found', 'reason' => null],
    ]);

    // One attempt row only: the failed claim. The foreign id made no row,
    // and its raw exception message never reaches the flash.
    $attempt = GenerationAttempt::query()->sole();

    expect($attempt->status)->toBe(GenerationStatus::Failed)
        ->and($attempt->failure_category)->toBe('dispatch_error')
        ->and($attempt->subject?->is($post))->toBeTrue();

    expect(json_encode(app('session.store')->get('batch_result')))->not->toContain('Simulated batch mixed dispatch failure.');
});
