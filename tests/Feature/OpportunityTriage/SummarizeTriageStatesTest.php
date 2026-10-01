<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobMatchCoverage;
use App\Exceptions\NoCareerProfileException;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\JobPosting;
use App\Support\OpportunityTriage\SummarizeTriageStates;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Focused coverage for App\Support\OpportunityTriage\SummarizeTriageStates
|--------------------------------------------------------------------------
|
| The derived per-posting triage stage (raw / analyzing / analysis-failed /
| analyzed / matching / match-failed / matched) plus the zero-filled
| coverage buckets and snapshot timestamps. Every assertion seeds only the
| same durable models the JobAnalysis/JobMatch controllers already read —
| JobAnalysis and JobMatch snapshots, GenerationAttempts, JobMatchFinding
| rows — so the stage precedence, snapshot selection (generated_at DESC,
| id DESC), current-profile scoping, and the "coverage shown only in
| matched" rule are pinned end to end. CurrentCareerProfile always exists
| except where a test asserts its absence.
|
*/

function triage(JobPosting ...$jobPostings): array
{
    return (new SummarizeTriageStates)->forJobPostings(collect($jobPostings)->pluck('id'));
}

function careerProfile(): CareerProfile
{
    return CareerProfile::factory()->create();
}

function postingFor(CareerProfile $profile): JobPosting
{
    return JobPosting::factory()->create(['career_profile_id' => $profile->id]);
}

function analysisFor(JobPosting $jobPosting, string $generatedAt): JobAnalysis
{
    return JobAnalysis::factory()->for($jobPosting, 'jobPosting')->create([
        'generated_at' => $generatedAt,
    ]);
}

function matchFor(CareerProfile $profile, JobAnalysis $analysis, string $generatedAt): JobMatch
{
    return JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'generated_at' => $generatedAt,
    ]);
}

function attemptFor(Model $subject, GenerationType $generationType, GenerationStatus $status, ?string $createdAt = null): GenerationAttempt
{
    $attributes = [
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->id,
        'generation_type' => $generationType,
        'status' => $status,
    ];

    // Explicit created_at pins the attempt's chronology against the
    // snapshot's generated_at — otherwise Eloquent stamps now(), which
    // would make in-flight and crash-leftover indistinguishable in tests.
    if ($createdAt !== null) {
        $attributes['created_at'] = $createdAt;
    }

    return GenerationAttempt::factory()->create($attributes);
}

function coverageRow(JobMatch $match, JobAnalysis $analysis, JobMatchCoverage $coverage): JobMatchFinding
{
    $finding = JobAnalysisFinding::factory()->for($analysis, 'jobAnalysis')->create();

    return JobMatchFinding::factory()
        ->for($match, 'jobMatch')
        ->for($finding, 'jobAnalysisFinding')
        ->create(['coverage' => $coverage]);
}

it('reports raw for a JobPosting with no analysis or match activity at all', function () {
    $jobPosting = postingFor(careerProfile());

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary)->toBe([
        'stage' => 'raw',
        'coverage' => null,
        'analysis_at' => null,
        'match_at' => null,
    ]);
});

it('reports analyzing while a job_analysis attempt is queued and no snapshot exists yet', function () {
    $jobPosting = postingFor(careerProfile());
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Queued);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzing')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBeNull()
        ->and($summary['match_at'])->toBeNull();
});

it('reports analyzing when a re-analysis is running, keeping the old snapshot timestamp', function () {
    $jobPosting = postingFor(careerProfile());
    analysisFor($jobPosting, '2026-08-20 09:00:00');
    // Strictly newer than the snapshot — a genuine in-flight re-analysis.
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Running, '2026-08-20 09:30:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzing')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('does not treat a stale running re-analysis as in flight when the selected snapshot is newer', function () {
    $jobPosting = postingFor(careerProfile());
    analysisFor($jobPosting, '2026-08-20 09:00:00');

    // Crash leftover: this attempt row starts before the successful
    // snapshot it would claim to supersede, so the snapshot must win.
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Running, '2026-08-20 08:59:59');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('does not let an equal-timestamp running re-analysis override the selected snapshot', function () {
    $jobPosting = postingFor(careerProfile());
    analysisFor($jobPosting, '2026-08-20 09:00:00');

    // An equal timestamp does not establish that the attempt began
    // after the successful snapshot — overriding must be strictly newer.
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Running, '2026-08-20 09:00:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('withholds coverage while a re-analysis is in flight, even when a prior match exists', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-08-20 09:00:00');
    $match = matchFor($profile, $analysis, '2026-08-21 09:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);
    // Strictly newer than the snapshot — a genuine in-flight re-analysis.
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Running, '2026-08-20 09:30:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzing')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBe('2026-08-21 09:00:00');
});

it('reports analysis-failed when the only analysis attempt failed and no snapshot exists', function () {
    $jobPosting = postingFor(careerProfile());
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Failed);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary)->toBe([
        'stage' => 'analysis-failed',
        'coverage' => null,
        'analysis_at' => null,
        'match_at' => null,
    ]);
});

it('keeps a prior snapshot as analyzed when the re-analysis attempt failed', function () {
    $jobPosting = postingFor(careerProfile());
    analysisFor($jobPosting, '2026-08-20 09:00:00');
    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Failed);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('reports analyzed for a JobPosting with an analysis snapshot but no match activity', function () {
    $jobPosting = postingFor(careerProfile());
    analysisFor($jobPosting, '2026-09-01 08:00:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-09-01 08:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('reports matched with per-bucket finding counts, zero-filled', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    $match = matchFor($profile, $analysis, '2026-09-02 08:00:00');

    coverageRow($match, $analysis, JobMatchCoverage::Supported);
    coverageRow($match, $analysis, JobMatchCoverage::Supported);
    coverageRow($match, $analysis, JobMatchCoverage::Partial);
    coverageRow($match, $analysis, JobMatchCoverage::NoEvidence);
    coverageRow($match, $analysis, JobMatchCoverage::NotAssessable);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 2,
            'partial' => 1,
            'no_evidence' => 1,
            'not_assessable' => 1,
        ])
        ->and($summary['analysis_at'])->toBe('2026-09-01 08:00:00')
        ->and($summary['match_at'])->toBe('2026-09-02 08:00:00');
});

it('reports explicit zeros for buckets with no findings rather than omitting them', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    $match = matchFor($profile, $analysis, '2026-09-02 08:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 1,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ]);
});

it('reports all-zero coverage for a matched JobMatch with no findings rows at all', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    matchFor($profile, $analysis, '2026-09-02 08:00:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 0,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ]);
});

it('reports matching while a re-match is in flight, with no coverage or match timestamp', function () {
    $jobPosting = postingFor(careerProfile());
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Running);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matching')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-09-01 08:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('reports matching over a prior successful match when a re-match is in flight', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-08-20 09:00:00');
    $match = matchFor($profile, $analysis, '2026-08-21 09:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);
    // Strictly newer than the match snapshot — a genuine in-flight re-match.
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Running, '2026-08-21 09:30:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matching')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBe('2026-08-21 09:00:00');
});

it('does not treat a stale running re-match as in flight when the selected match is newer', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-08-20 09:00:00');
    $match = matchFor($profile, $analysis, '2026-08-21 09:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);

    // Crash leftover: this attempt row starts before the successful
    // match it would claim to supersede, so the match must win.
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Running, '2026-08-21 08:59:59');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 1,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ])
        ->and($summary['match_at'])->toBe('2026-08-21 09:00:00');
});

it('does not let an equal-timestamp running re-match override the selected match', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-08-20 09:00:00');
    $match = matchFor($profile, $analysis, '2026-08-21 09:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);

    // An equal timestamp does not establish that the attempt began
    // after the successful match — overriding must be strictly newer.
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Running, '2026-08-21 09:00:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 1,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ])
        ->and($summary['match_at'])->toBe('2026-08-21 09:00:00');
});

it('reports match-failed when the selected analysis has a failed match attempt and no completed match', function () {
    $jobPosting = postingFor(careerProfile());
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Failed);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('match-failed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-09-01 08:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('keeps a prior matched snapshot as matched when the re-match attempt failed', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-08-20 09:00:00');
    $match = matchFor($profile, $analysis, '2026-08-21 09:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Partial);
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Failed);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 0,
            'partial' => 1,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ])
        ->and($summary['match_at'])->toBe('2026-08-21 09:00:00');
});

it('selects the latest analysis by generated_at even when an older analysis has the higher id', function () {
    // The completed match sits on the older, higher-id analysis — the
    // selected (newer) analysis has no match of its own, so the stage is
    // analyzed and the stale match must not surface.
    $profile = careerProfile();
    $jobPosting = postingFor($profile);

    analysisFor($jobPosting, '2026-10-01 08:00:00'); // newer, lower id — the selected one
    $older = analysisFor($jobPosting, '2026-08-01 08:00:00'); // older, higher id
    $staleMatch = matchFor($profile, $older, '2026-08-02 08:00:00');
    coverageRow($staleMatch, $older, JobMatchCoverage::Supported);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-10-01 08:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('breaks a generated_at tie by higher id, as the detail pages do', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);

    $first = analysisFor($jobPosting, '2026-09-01 08:00:00'); // lower id
    $second = analysisFor($jobPosting, '2026-09-01 08:00:00'); // same generated_at, higher id — the selected one
    $match = matchFor($profile, $second, '2026-09-02 08:00:00');
    coverageRow($match, $second, JobMatchCoverage::Supported);

    // A match on the lower-id analysis must lose the tiebreak — its
    // different coverage profile would show up if selection drifted.
    $staleMatch = matchFor($profile, $first, '2026-09-03 08:00:00');
    coverageRow($staleMatch, $first, JobMatchCoverage::NoEvidence);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 1,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ])
        ->and($summary['analysis_at'])->toBe('2026-09-01 08:00:00')
        ->and($summary['match_at'])->toBe('2026-09-02 08:00:00');
});

it('ignores an in-flight match attempt on a superseded analysis', function () {
    $jobPosting = postingFor(careerProfile());

    analysisFor($jobPosting, '2026-10-01 08:00:00'); // newer, lower id — the selected one
    $older = analysisFor($jobPosting, '2026-08-01 08:00:00');
    attemptFor($older, GenerationType::JobMatch, GenerationStatus::Running);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-10-01 08:00:00')
        ->and($summary['match_at'])->toBeNull();
});

it('does not let a failed match attempt on a superseded analysis demote the selected stage', function () {
    $jobPosting = postingFor(careerProfile());

    analysisFor($jobPosting, '2026-10-01 08:00:00'); // newer, lower id — the selected one
    $older = analysisFor($jobPosting, '2026-08-01 08:00:00');
    attemptFor($older, GenerationType::JobMatch, GenerationStatus::Failed);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['match_at'])->toBeNull();
});

it('counts only JobMatches scoped to the current CareerProfile', function () {
    $current = careerProfile(); // created first — lowest id, the resolved current profile
    $other = careerProfile();

    $jobPosting = postingFor($current);
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    $foreignMatch = matchFor($other, $analysis, '2026-09-02 08:00:00');
    coverageRow($foreignMatch, $analysis, JobMatchCoverage::Supported);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzed')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['match_at'])->toBeNull();
});

it('reads snapshot generated_at columns, never the attempt lifecycle timestamps', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-09-01 09:30:00');
    $match = matchFor($profile, $analysis, '2026-09-02 10:15:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);

    // Both lifecycle timestamps are deliberately distinct from the
    // snapshot columns — if either leaked in, the assertion below fails.
    GenerationAttempt::factory()->create([
        'subject_type' => $jobPosting->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Succeeded,
        'queued_at' => '2030-01-02 01:00:00',
        'started_at' => '2030-01-02 02:00:00',
        'finished_at' => '2030-01-02 03:04:05',
        'result_id' => $analysis->id,
    ]);
    GenerationAttempt::factory()->create([
        'subject_type' => $analysis->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Succeeded,
        'queued_at' => '2030-01-03 01:00:00',
        'started_at' => '2030-01-03 02:00:00',
        'finished_at' => '2030-01-03 03:04:05',
        'result_id' => $match->id,
    ]);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['analysis_at'])->toBe('2026-09-01 09:30:00')
        ->and($summary['match_at'])->toBe('2026-09-02 10:15:00');
});

it('summarizes several JobPostings independently in one bulk call', function () {
    $profile = careerProfile();

    $matched = postingFor($profile);
    $matchedAnalysis = analysisFor($matched, '2026-09-01 08:00:00');
    $match = matchFor($profile, $matchedAnalysis, '2026-09-02 08:00:00');
    coverageRow($match, $matchedAnalysis, JobMatchCoverage::Supported);

    $analyzing = postingFor($profile);
    attemptFor($analyzing, GenerationType::JobAnalysis, GenerationStatus::Queued);

    $raw = postingFor($profile);

    $matchFailed = postingFor($profile);
    $matchFailedAnalysis = analysisFor($matchFailed, '2026-09-01 08:00:00');
    attemptFor($matchFailedAnalysis, GenerationType::JobMatch, GenerationStatus::Failed);

    $summaries = triage($matched, $analyzing, $raw, $matchFailed);

    expect($summaries)->toHaveCount(4)
        ->and($summaries[$matched->id]['stage'])->toBe('matched')
        ->and($summaries[$matched->id]['coverage'])->toBe([
            'supported' => 1,
            'partial' => 0,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ])
        ->and($summaries[$analyzing->id]['stage'])->toBe('analyzing')
        ->and($summaries[$analyzing->id]['coverage'])->toBeNull()
        ->and($summaries[$raw->id]['stage'])->toBe('raw')
        ->and($summaries[$matchFailed->id]['stage'])->toBe('match-failed');
});

it('prefers analyzing over a concurrent in-flight match attempt', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-08-20 09:00:00');
    $match = matchFor($profile, $analysis, '2026-08-21 09:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Supported);

    attemptFor($jobPosting, GenerationType::JobAnalysis, GenerationStatus::Running, '2026-08-20 09:30:00');
    attemptFor($analysis, GenerationType::JobMatch, GenerationStatus::Running, '2026-08-21 09:30:00');

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('analyzing')
        ->and($summary['coverage'])->toBeNull()
        ->and($summary['analysis_at'])->toBe('2026-08-20 09:00:00')
        ->and($summary['match_at'])->toBe('2026-08-21 09:00:00');
});

it('returns an empty array for an empty JobPosting id list', function () {
    careerProfile();

    expect((new SummarizeTriageStates)->forJobPostings(collect([])))->toBe([]);
});

it('throws NoCareerProfileException when no CareerProfile exists yet', function () {
    expect(fn () => (new SummarizeTriageStates)->forJobPostings(collect([42])))
        ->toThrow(NoCareerProfileException::class);
});

it('ignores resume_variant attempts hanging off a matched JobMatch', function () {
    $profile = careerProfile();
    $jobPosting = postingFor($profile);
    $analysis = analysisFor($jobPosting, '2026-09-01 08:00:00');
    $match = matchFor($profile, $analysis, '2026-09-02 08:00:00');
    coverageRow($match, $analysis, JobMatchCoverage::Partial);

    // Resume generation is not a triage stage — neither an in-flight nor
    // a failed resume attempt may change the stage, coverage, or times.
    attemptFor($match, GenerationType::ResumeVariant, GenerationStatus::Running);
    attemptFor($match, GenerationType::ResumeVariant, GenerationStatus::Failed);

    $summary = triage($jobPosting)[$jobPosting->id];

    expect($summary['stage'])->toBe('matched')
        ->and($summary['coverage'])->toBe([
            'supported' => 0,
            'partial' => 1,
            'no_evidence' => 0,
            'not_assessable' => 0,
        ])
        ->and($summary['match_at'])->toBe('2026-09-02 08:00:00');
});
