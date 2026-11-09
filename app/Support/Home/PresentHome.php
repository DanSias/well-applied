<?php

namespace App\Support\Home;

use App\Enums\ClarificationSessionStatus;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\ClarificationSession;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeProfile;
use App\Models\ResumeVariant;
use App\Support\CurrentCareerProfile;
use App\Support\OpportunityTriage\SummarizeTriageStates;
use App\Support\ReadinessReview\PresentReadiness;
use App\Support\ResumeRevision\JobResumes;
use App\Support\ResumeRevision\PresentResumeWorkspace;
use Illuminate\Support\Carbon;

/**
 * The Home page's data: where the job search stands, what needs the user, and what they were last working on.
 *
 * Everything here is derived live from records that already exist (triage stage, the job's current and final resume,
 * readiness reviews, the resume's own review findings, pending clarification sessions). Nothing is stored for Home and
 * nothing is scored or ranked: the only ordering is "most recent activity first", and attention items are ordered by
 * what blocks the user soonest.
 *
 * Resume detail (readiness, findings) is read only for the few resumes that can be shown, never for every job.
 */
final class PresentHome
{
    /** How many "continue" and "needs attention" rows Home shows. */
    private const CONTINUE_LIMIT = 4;

    private const ATTENTION_LIMIT = 5;

    private const TRIAGE_LABELS = [
        'raw' => 'Not analyzed',
        'analyzing' => 'Analyzing',
        'analysis-failed' => 'Analysis failed',
        'analyzed' => 'Analyzed',
        'matching' => 'Matching',
        'match-failed' => 'Match failed',
        'matched' => 'Matched',
    ];

    public function __construct(
        private readonly SummarizeTriageStates $triage,
        private readonly JobResumes $jobResumes,
        private readonly PresentReadiness $readiness,
        private readonly PresentResumeWorkspace $workspace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $profile = CurrentCareerProfile::tryResolve();

        if ($profile === null) {
            return ['has_profile' => false] + $this->empty();
        }

        $jobs = $profile->jobPostings()
            ->where('status', '!=', JobPostingLifecycleStatus::Closed->value)
            ->get(['id', 'company', 'title', 'created_at'])
            ->keyBy('id');

        if ($jobs->isEmpty()) {
            return ['has_profile' => true] + $this->empty($this->quickAccess($profile->id));
        }

        $triage = $this->triage->forJobPostings($jobs->keys());
        $resumes = collect($this->resumesByJob($jobs->modelKeys()));
        $pending = ClarificationSession::query()
            ->whereIn('job_posting_id', $jobs->keys())
            ->where('status', ClarificationSessionStatus::Pending)
            ->get()
            ->keyBy('job_posting_id');

        $stages = collect($triage)->countBy('stage');
        $finals = $resumes->filter(fn (array $r) => $r['final'] !== null);
        $working = $resumes->filter(fn (array $r) => $r['final'] === null);

        $overview = [
            'opportunities' => $jobs->count(),
            'not_analyzed' => (int) $stages->get('raw', 0),
            'in_flight' => (int) ($stages->get('analyzing', 0) + $stages->get('matching', 0)),
            'resumes_in_progress' => $working->count(),
            'resumes_final' => $finals->count(),
        ];

        $rows = $this->rows($jobs->all(), $triage, $resumes->all(), $pending->all());

        return [
            'has_profile' => true,
            'overview' => $overview,
            'attention' => $this->attention($rows, $stages->all()),
            'continue' => $this->continue($rows),
            'quick_access' => $this->quickAccess($profile->id),
        ];
    }

    /**
     * Every job that has resume work, with the resume to open: the final one when chosen, otherwise the working copy.
     *
     * @param  array<int, int>  $jobIds
     * @return array<int, array{current: ResumeVariant, final: ResumeVariant|null, selection_at: Carbon|null}>
     */
    private function resumesByJob(array $jobIds): array
    {
        $withResumes = ResumeVariant::query()
            ->whereNotNull('resume_profile_synthesis_id')
            ->whereIn('job_analysis_id', JobAnalysis::query()->whereIn('job_posting_id', $jobIds)->select('id'))
            ->with('jobAnalysis:id,job_posting_id')
            ->get(['id', 'job_analysis_id'])
            ->map(fn (ResumeVariant $v) => $v->jobAnalysis->job_posting_id)
            ->unique();

        $selections = JobResumeSelection::query()->whereIn('job_posting_id', $withResumes)->get()->keyBy('job_posting_id');
        $resumes = [];

        foreach ($withResumes as $jobId) {
            $posting = JobPosting::query()->findOrFail($jobId);
            $current = $this->jobResumes->current($posting);

            if ($current !== null) {
                $resumes[$jobId] = [
                    'current' => $current,
                    'final' => $this->jobResumes->preferred($posting),
                    'selection_at' => $selections->get($jobId)?->updated_at,
                ];
            }
        }

        return $resumes;
    }

    /**
     * One row per job that has something to continue or act on, with the facts both lists need.
     *
     * @param  array<int, JobPosting>  $jobs
     * @param  array<int, array<string, mixed>>  $triage
     * @param  array<int, array<string, mixed>>  $resumes
     * @param  array<int, ClarificationSession>  $pending
     * @return list<array<string, mixed>>
     */
    private function rows(array $jobs, array $triage, array $resumes, array $pending): array
    {
        $rows = [];

        foreach ($jobs as $id => $job) {
            $stage = $triage[$id]['stage'];
            $resume = $resumes[$id] ?? null;
            $session = $pending[$id] ?? null;

            if ($resume === null && $session === null && $stage === 'raw') {
                continue;
            }

            $activity = collect([
                $triage[$id]['analysis_at'] ?? null,
                $triage[$id]['match_at'] ?? null,
                $session?->updated_at,
                $resume['current']->generated_at ?? null,
                $resume['selection_at'] ?? null,
            ])->filter()->map(fn ($t) => Carbon::parse($t))->max();

            $rows[] = [
                'job' => $job,
                'stage' => $stage,
                'resume' => $resume,
                'pending_questions' => $session !== null,
                'activity_at' => $activity,
            ];
        }

        usort($rows, fn (array $a, array $b) => ($b['activity_at']?->getTimestamp() ?? 0) <=> ($a['activity_at']?->getTimestamp() ?? 0));

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function continue(array $rows): array
    {
        $out = [];

        foreach (array_slice($rows, 0, self::CONTINUE_LIMIT) as $row) {
            $job = $row['job'];
            $resume = $row['resume'];
            $open = $resume === null ? null : ($resume['final'] ?? $resume['current']);

            if ($resume !== null) {
                $status = $resume['final'] !== null ? ['label' => 'Final resume chosen', 'tone' => 'ok'] : ['label' => 'Resume in progress', 'tone' => 'accent'];
            } elseif ($row['pending_questions']) {
                $status = ['label' => 'Questions waiting', 'tone' => 'warn'];
            } else {
                $status = ['label' => self::TRIAGE_LABELS[$row['stage']], 'tone' => str_ends_with($row['stage'], 'failed') ? 'bad' : 'neutral'];
            }

            $out[] = [
                'job_id' => $job->id,
                'title' => $job->title,
                'company' => $job->company,
                'status' => $status,
                'activity_at' => $row['activity_at']?->toIso8601String(),
                'resume_variant_id' => $open?->id,
                'action' => $resume !== null ? 'Open resume' : 'Open opportunity',
            ];
        }

        return $out;
    }

    /**
     * Genuinely actionable items, most blocking first: questions the pipeline is waiting on, then resumes that need a
     * look, then single summary lines for stages a person would batch (failures, un-analyzed postings).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $stages
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function attention(array $rows, array $stages): array
    {
        $items = [];

        foreach ($rows as $row) {
            if ($row['pending_questions']) {
                $items[] = $this->item($row['job'], 'questions', 'Questions are waiting before the resume can be written.', 'Answer questions');
            }
        }

        $resumeRows = collect($rows)->filter(fn (array $r) => $r['resume'] !== null && $r['resume']['final'] === null)->take(self::ATTENTION_LIMIT);

        foreach ($resumeRows as $row) {
            $variant = $row['resume']['current'];
            $reasons = [];
            $open = count(array_filter(
                $this->workspace->present($row['job'], $variant)['attention'],
                fn (array $a) => $a['severity'] !== 'informational',
            ));

            if ($open > 0) {
                $reasons[] = $open === 1 ? '1 item to check' : "{$open} items to check";
            }

            $state = $this->readiness->present($variant)['state'];
            $reasons[] = match ($state) {
                'none' => 'not reviewed against the job yet',
                'stale' => 'readiness review is out of date',
                'failed' => 'the last readiness review failed',
                default => null,
            };
            $reasons = array_values(array_filter($reasons));

            if ($reasons !== []) {
                $items[] = $this->item($row['job'], 'resume', ucfirst(implode(', ', $reasons)).'.', 'Open resume', $variant->id);
            }
        }

        $failed = (int) ($stages['analysis-failed'] ?? 0) + (int) ($stages['match-failed'] ?? 0);

        if ($failed > 0) {
            $items[] = ['key' => 'failed', 'kind' => 'summary', 'title' => $failed === 1 ? '1 opportunity failed analysis or matching' : "{$failed} opportunities failed analysis or matching", 'company' => null, 'message' => 'Open them to see why and retry.', 'action' => 'Review failures', 'job_id' => null, 'resume_variant_id' => null, 'filter' => 'failed'];
        }

        $raw = (int) ($stages['raw'] ?? 0);

        if ($raw > 0) {
            $items[] = ['key' => 'raw', 'kind' => 'summary', 'title' => $raw === 1 ? '1 opportunity is not analyzed yet' : "{$raw} opportunities are not analyzed yet", 'company' => null, 'message' => 'Analysis is the first step toward matching and a tailored resume.', 'action' => 'Triage opportunities', 'job_id' => null, 'resume_variant_id' => null, 'filter' => 'raw'];
        }

        return ['total' => count($items), 'items' => array_slice($items, 0, self::ATTENTION_LIMIT)];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(JobPosting $job, string $kind, string $message, string $action, ?int $variantId = null): array
    {
        return ['key' => "{$kind}-{$job->id}", 'kind' => $kind, 'title' => $job->title, 'company' => $job->company, 'message' => $message, 'action' => $action, 'job_id' => $job->id, 'resume_variant_id' => $variantId, 'filter' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function quickAccess(int $profileId): array
    {
        $profiles = ResumeProfile::query()->where('career_profile_id', $profileId)->get(['id', 'status']);

        return ['resume_profiles' => $profiles->count()];
    }

    /**
     * @param  array<string, mixed>  $quickAccess
     * @return array<string, mixed>
     */
    private function empty(array $quickAccess = []): array
    {
        return [
            'overview' => ['opportunities' => 0, 'not_analyzed' => 0, 'in_flight' => 0, 'resumes_in_progress' => 0, 'resumes_final' => 0],
            'attention' => ['total' => 0, 'items' => []],
            'continue' => [],
            'quick_access' => $quickAccess + ['resume_profiles' => 0],
        ];
    }
}
