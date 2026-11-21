<?php

namespace App\Support\Opportunities;

use App\Enums\ClarificationSessionStatus;
use App\Enums\JobDisposition;
use App\Models\ClarificationSession;
use App\Models\JobPosting;
use App\Support\ApplicationInspection\SummarizeInspectionStates;
use App\Support\CurrentCareerProfile;
use App\Support\JobDecisions\ReportJobsWithWork;
use App\Support\JobDiscovery\ProviderAttribution;
use App\Support\JobFreshness\JobFreshness;
use App\Support\OpportunityTriage\SummarizeTriageStates;
use App\Support\ResumeRevision\JobResumes;
use App\Support\Submissions\PresentSubmissions;
use Illuminate\Support\Collection;

/**
 * The Opportunities page's data. An opportunity is not a record of its own: it is a JobPosting whose disposition is
 * `pursued`, so everything here is read from what already exists (the job, its resumes through JobResumes, the derived
 * processing stage, the inspection summary, pending clarification). Nothing is stored for this page, and there is no
 * opportunity stage of its own: the only "state" shown is the resume's (none / in progress / final), which is exactly
 * the state the Overview counts.
 *
 * Also lists the jobs that already have resume or application-inspection work but are not pursued, so that work can be
 * reviewed and pursued deliberately. Reviewing changes nothing.
 */
final class PresentOpportunities
{
    public function __construct(
        private readonly JobResumes $resumes,
        private readonly SummarizeTriageStates $triage,
        private readonly SummarizeInspectionStates $inspection,
        private readonly ReportJobsWithWork $existingWork,
        private readonly PresentSubmissions $submissions,
    ) {}

    /**
     * @param  string  $resume  one of all, none, in-progress, final
     * @return array{opportunities: list<array<string, mixed>>, counts: array<string, int>, review: list<array<string, mixed>>}
     */
    public function present(string $resume = 'all'): array
    {
        $jobs = CurrentCareerProfile::resolve()->jobPostings()
            ->where('disposition', JobDisposition::Pursued->value)
            ->orderByDesc('pursued_at')->orderByDesc('id')->get();

        $ids = $jobs->pluck('id');
        $states = $this->resumes->stateByJob($ids);
        $triage = $this->triage->forJobPostings($ids);
        $inspection = $this->inspection->forJobPostings($ids);
        $applied = $this->submissions->indicatorsByJob($ids);
        $pending = ClarificationSession::query()->whereIn('job_posting_id', $ids)->where('status', ClarificationSessionStatus::Pending)->pluck('job_posting_id')->flip();

        $rows = $jobs->map(fn (JobPosting $job) => $this->row($job, $states, $triage, $inspection, $pending, $applied))->values()->all();

        return [
            'opportunities' => array_values(array_filter($rows, fn (array $r) => $resume === 'all' || $r['resume']['state'] === $resume)),
            'counts' => [
                'all' => count($rows),
                'none' => count(array_filter($rows, fn (array $r) => $r['resume']['state'] === 'none')),
                'in-progress' => count(array_filter($rows, fn (array $r) => $r['resume']['state'] === 'in-progress')),
                'final' => count(array_filter($rows, fn (array $r) => $r['resume']['state'] === 'final')),
            ],
            'review' => $this->review(),
        ];
    }

    /**
     * One pursued job, in exactly the shape (and with exactly the derived next step) of a row on the list, for the
     * Opportunity workspace. Same lookups, same rules: nothing is recomputed differently for a single job.
     *
     * @return array<string, mixed>
     */
    public function forJob(JobPosting $job): array
    {
        $ids = collect([$job->id]);
        $pending = ClarificationSession::query()->where('job_posting_id', $job->id)->where('status', ClarificationSessionStatus::Pending)->pluck('job_posting_id')->flip();

        return $this->row($job, $this->resumes->stateByJob($ids), $this->triage->forJobPostings($ids), $this->inspection->forJobPostings($ids), $pending, $this->submissions->indicatorsByJob($ids));
    }

    /**
     * @param  array<int, string>  $states
     * @param  array<int, array<string, mixed>>  $triage
     * @param  array<int, array<string, mixed>>  $inspection
     * @param  Collection<int, int>  $pending
     * @param  array<int, array<string, mixed>>  $applied
     * @return array<string, mixed>
     */
    private function row(JobPosting $job, array $states, array $triage, array $inspection, $pending, array $applied): array
    {
        $state = $states[$job->id] ?? 'none';
        $current = $state === 'none' ? null : $this->resumes->current($job);
        $final = $state === 'final' ? $this->resumes->preferred($job) : null;
        $stage = $triage[$job->id]['stage'];
        $questions = $pending->has($job->id);

        return [
            'id' => $job->id,
            'title' => $job->title,
            'company' => $job->company,
            'location' => $job->location,
            'remote_status' => $job->remote_status?->value,
            'compensation_min' => $job->compensation_min,
            'compensation_max' => $job->compensation_max,
            'compensation_currency' => $job->compensation_currency,
            'source_url' => $job->source_url,
            'attribution' => ProviderAttribution::for($job),
            'posting_status' => $job->status->value,
            'freshness' => JobFreshness::view($job),
            'pursued_at' => $job->pursued_at?->toIso8601String(),
            'stage' => $stage,
            'resume' => [
                'state' => $state,
                'versions' => $state === 'none' ? 0 : $this->resumes->variants($job)->count(),
                'current_variant_id' => $current?->id,
                'final_variant_id' => $final?->id,
            ],
            'inspection' => $inspection[$job->id],
            // An application the person recorded themselves, or null; never the inspection above.
            'submission' => $applied[$job->id] ?? null,
            'pending_questions' => $questions,
            'next' => $this->next($state, $stage, $questions),
        ];

    }

    /**
     * Jobs with resume or inspection work that nobody has pursued, strongest evidence of intent first: a final resume,
     * then the most resume versions, then inspection drafts alone. Strength only orders the list; it never promotes.
     *
     * @return list<array<string, mixed>>
     */
    public function review(): array
    {
        $rows = array_values(array_filter($this->existingWork->report(), fn (array $r) => $r['disposition'] !== JobDisposition::Pursued->value));

        usort($rows, fn (array $a, array $b) => [$b['final_resume_variant_id'] !== null, $b['resume_variants'], count($b['applications'])]
            <=> [$a['final_resume_variant_id'] !== null, $a['resume_variants'], count($a['applications'])]);

        // Job freshness (OFF by default): each "existing work to review" card carries the job's freshness label. Absent with the feature off.
        if (JobFreshness::enabled() && $rows !== []) {
            $jobs = JobPosting::query()->whereIn('id', array_column($rows, 'job_id'))->get()->keyBy('id');
            $rows = array_map(fn (array $r) => $r + ['freshness' => isset($jobs[$r['job_id']]) ? JobFreshness::view($jobs[$r['job_id']]) : null], $rows);
        }

        return $rows;
    }

    /**
     * The one next step, derived from what exists. The page turns the kind into a label and a destination.
     */
    private function next(string $resumeState, string $stage, bool $pendingQuestions): string
    {
        return match (true) {
            $pendingQuestions => 'answer_questions',
            $resumeState === 'final' => 'review_final',
            $resumeState === 'in-progress' => 'continue_resume',
            in_array($stage, ['analyzing', 'matching'], true) => 'processing',
            in_array($stage, ['raw', 'analysis-failed'], true) => 'analyze',
            default => 'prepare_resume',
        };
    }
}
