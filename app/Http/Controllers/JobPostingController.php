<?php

namespace App\Http\Controllers;

use App\Enums\DismissalReason;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobDisposition;
use App\Http\Requests\StoreJobPostingRequest;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\ApplicationInspection\SummarizeInspectionStates;
use App\Support\Clarification\PresentApprovedEvidence;
use App\Support\CurrentCareerProfile;
use App\Support\GenerationAttempt\PresentGenerationAttempt;
use App\Support\JobQualification\PresentJobQualification;
use App\Support\Navigation\JobHome;
use App\Support\OpportunityTriage\SummarizeTriageStates;
use App\Support\ResumeRevision\JobResumes;
use App\Support\TailoredResume\PresentTailoredResume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Job intake: capturing a target job posting's verbatim source material.
 * No analysis, matching, or CareerFact selection happens here — see
 * docs/domain-model.md "JobPosting".
 */
class JobPostingController extends Controller
{
    /**
     * "Opportunities" in the UI — the JobPosting domain model itself is
     * unchanged; only the product-facing label differs. See
     * docs/application-inspector.md "Opportunities index".
     */
    public function index(Request $request, SummarizeInspectionStates $summarizer, SummarizeTriageStates $triageSummarizer, JobResumes $jobResumes): Response
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $triage = (string) $request->query('triage', 'all');
        $triageStages = $this->triageStagesForFilter($triage);
        // Resume work, per job: 'in-progress' (a resume exists, none marked final) or 'final'. Anything else is no restriction.
        $resume = in_array($request->query('resume'), ['in-progress', 'final'], true) ? (string) $request->query('resume') : 'all';
        $perPage = $this->perPage($request);
        // The decision view: the inbox (jobs nobody has decided on) unless another is asked for. Anything unrecognized is the inbox.
        $disposition = in_array($request->query('disposition'), ['inbox', 'shortlist', 'dismissed', 'all'], true) ? (string) $request->query('disposition') : 'inbox';

        // The job open in the review drawer, if any (`?job=ID`). Only its id is read here; the detail is loaded lazily below.
        $selectedId = ctype_digit((string) $request->query('job')) ? (int) $request->query('job') : null;

        $query = CurrentCareerProfile::resolve()->jobPostings()->latest()->orderByDesc('id');

        match ($disposition) {
            'inbox' => $query->where('disposition', JobDisposition::New->value),
            'shortlist' => $query->where('disposition', JobDisposition::Shortlisted->value),
            'dismissed' => $query->where('disposition', JobDisposition::Dismissed->value),
            default => null,
        };

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        // Inspection and triage state are derived from other tables, so
        // filtering on them needs every matching posting's state. Without
        // those filters the page can be cut in SQL and only its rows summarized.
        if ($status === 'all' && $triageStages === null && $resume === 'all') {
            $total = (clone $query)->count();
            $page = $this->page($request, $total, $perPage);
            $jobs = $query->forPage($page, $perPage)->get();
            $inspectionStates = $summarizer->forJobPostings($jobs->pluck('id'));
            $triageStates = $triageSummarizer->forJobPostings($jobs->pluck('id'));
        } else {
            $jobs = $query->get();
            $inspectionStates = $summarizer->forJobPostings($jobs->pluck('id'));
            $triageStates = $triageSummarizer->forJobPostings($jobs->pluck('id'));

            if ($status === 'not_inspected') {
                $jobs = $jobs->filter(fn (JobPosting $job) => $inspectionStates[$job->id]['state'] === 'not_inspected');
            } elseif ($status === 'inspected') {
                $jobs = $jobs->filter(fn (JobPosting $job) => in_array($inspectionStates[$job->id]['state'], ['inspected', 'unsupported'], true));
            }

            if ($triageStages !== null) {
                $jobs = $jobs->filter(
                    fn (JobPosting $job) => in_array($triageStates[$job->id]['stage'], $triageStages, true),
                );
            }

            if ($resume !== 'all') {
                $resumeStates = $jobResumes->stateByJob($jobs->pluck('id'));
                $jobs = $jobs->filter(fn (JobPosting $job) => ($resumeStates[$job->id] ?? null) === $resume);
            }

            $total = $jobs->count();
            $page = $this->page($request, $total, $perPage);
            $jobs = $jobs->forPage($page, $perPage);
        }

        return Inertia::render('jobs/index', [
            'jobs' => $jobs->map(fn (JobPosting $job) => $this->transformSummary($job, $inspectionStates[$job->id], $triageStates[$job->id]))->values()->all(),
            // `triage` is normalized so the echoed filter always reflects
            // what was actually applied — an invalid value degrades to
            // `all` in both behavior and props.
            'filters' => ['q' => $search, 'status' => $status, 'triage' => $triageStages === null ? 'all' : $triage, 'resume' => $resume, 'disposition' => $disposition, 'job' => $selectedId],
            'disposition_counts' => $this->dispositionCounts(),
            // The drawer's job, fetched on demand and never with the list: the full description would multiply the page
            // payload by the page size. Null when no job is open, or when the id is not one of this profile's jobs.
            'selected_job' => fn () => $selectedId === null ? null : $this->selectedJob($selectedId, $summarizer, $triageStates),
            // Normalized the same way: an out-of-range page or an
            // unsupported page size degrades to a valid one and is echoed.
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($total, $page * $perPage),
                'per_page_options' => self::PER_PAGE_OPTIONS,
            ],
        ]);
    }

    /**
     * One job for the review drawer: the same summary the list row shows (so the drawer and the list always agree on
     * disposition, stage and inspection), plus what the list leaves out: the verbatim description and the latest
     * analysis in brief. Everything else about the job stays on its own page.
     *
     * @param  array<int, array<string, mixed>>  $listTriage
     * @return array<string, mixed>|null
     */
    private function selectedJob(int $id, SummarizeInspectionStates $inspection, array $listTriage): ?array
    {
        $job = CurrentCareerProfile::resolve()->jobPostings()->find($id);

        if ($job === null) {
            return null;
        }

        $ids = collect([$job->id]);
        $triage = $listTriage[$job->id] ?? app(SummarizeTriageStates::class)->forJobPostings($ids)[$job->id];
        $analysis = $job->jobAnalyses()->latest('generated_at')->latest('id')->first();
        $findings = $analysis?->findings()->orderBy('id')->get(['category', 'statement', 'label']);

        return $this->transformSummary($job, $inspection->forJobPostings($ids)[$job->id], $triage) + [
            'description' => $job->description,
            'home_url' => JobHome::url($job),
            'qualification' => app(PresentJobQualification::class)->forJob($job),
            'analysis' => $analysis === null ? null : [
                'role_summary' => $analysis->role_summary,
                'seniority' => $analysis->overall_seniority?->value,
                'requirements' => $findings->filter(fn ($f) => $f->category === JobAnalysisFindingCategory::RequiredQualification)->pluck('statement')->take(8)->values()->all(),
                'technologies' => $findings->filter(fn ($f) => $f->category === JobAnalysisFindingCategory::Technology)->map(fn ($f) => $f->label ?? $f->statement)->unique()->take(14)->values()->all(),
            ],
        ];
    }

    /**
     * How many of the profile's jobs sit in each decision view, regardless of the other filters. `all` includes pursued jobs.
     *
     * @return array{inbox: int, shortlist: int, dismissed: int, all: int}
     */
    private function dispositionCounts(): array
    {
        $counts = CurrentCareerProfile::resolve()->jobPostings()->selectRaw('disposition, count(*) as total')->groupBy('disposition')->pluck('total', 'disposition');

        return [
            'inbox' => (int) $counts->get(JobDisposition::New->value, 0),
            'shortlist' => (int) $counts->get(JobDisposition::Shortlisted->value, 0),
            'dismissed' => (int) $counts->get(JobDisposition::Dismissed->value, 0),
            'all' => (int) $counts->sum(),
        ];
    }

    /** Page sizes the list offers; anything else degrades to the default. */
    private const PER_PAGE_OPTIONS = [25, 50, 100];

    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::PER_PAGE_OPTIONS[0]);

        return in_array($requested, self::PER_PAGE_OPTIONS, true) ? $requested : self::PER_PAGE_OPTIONS[0];
    }

    /** The requested page, clamped into 1..last so a stale link still shows rows. */
    private function page(Request $request, int $total, int $perPage): int
    {
        $last = max(1, (int) ceil($total / $perPage));

        return min($last, max(1, (int) $request->query('page', 1)));
    }

    /**
     * The canonical triage stages allowed by each triage filter value —
     * a deterministic UI grouping over the seven stages
     * SummarizeTriageStates derives from the durable generation history.
     * `all` (and any unrecognized value, for safe degradation) means no
     * triage-stage restriction, so they map to null. Navigation grouping
     * only: never a score, rank, or recommendation, and never a persisted
     * column — the stage itself always comes from the summarizer.
     *
     * @return list<string>|null
     */
    private function triageStagesForFilter(string $triage): ?array
    {
        return match ($triage) {
            'raw' => ['raw'],
            'in-progress' => ['analyzing', 'matching'],
            'analyzed' => ['analyzed'],
            'matched' => ['matched'],
            'failed' => ['analysis-failed', 'match-failed'],
            default => null,
        };
    }

    public function create(): Response
    {
        return Inertia::render('jobs/create');
    }

    public function store(StoreJobPostingRequest $request): RedirectResponse
    {
        $job = CurrentCareerProfile::resolve()
            ->jobPostings()
            ->create($request->validated());

        return redirect()->route('jobs.show', $job);
    }

    public function show(JobPosting $jobPosting, PresentGenerationAttempt $presenter, SummarizeInspectionStates $summarizer, PresentTailoredResume $tailoredResume, PresentApprovedEvidence $approvedEvidence): Response
    {
        return Inertia::render('jobs/show', [
            'job' => $this->transformDetail($jobPosting, $presenter, $summarizer),
            'tailored_resume' => $tailoredResume->present($jobPosting),
            'resume_profiles' => $tailoredResume->profiles($jobPosting),
            'approved_evidence' => $approvedEvidence->present($jobPosting),
        ]);
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array<string, mixed>  $triage
     * @return array<string, mixed>
     */
    private function transformSummary(JobPosting $job, array $inspection, array $triage): array
    {
        return [
            'id' => $job->id,
            'company' => $job->company,
            'title' => $job->title,
            'location' => $job->location,
            'has_source_url' => $job->source_url !== null,
            'source_url' => $job->source_url,
            'captured_at' => $job->created_at?->toDateString(),
            'inspection' => $inspection,
            'triage' => $triage,
            'discovery' => $this->transformDiscoveryMetadata($job),
            'disposition' => $this->transformDisposition($job),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDisposition(JobPosting $job): array
    {
        return [
            'value' => $job->disposition->value,
            'reason' => $job->disposition_reason,
            'reason_label' => DismissalReason::tryFrom((string) $job->disposition_reason)?->label(),
            'decided_at' => $job->dispositioned_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDiscoveryMetadata(JobPosting $job): array
    {
        return [
            'source' => $job->discovery_source->value,
            'canonical_source' => $job->canonical_source?->value,
            'discovered_at' => $job->isDiscovered() ? $job->discovered_at->toDateString() : null,
            'remote_status' => $job->remote_status?->value,
            'employment_type' => $job->employment_type,
            'compensation_min' => $job->compensation_min,
            'compensation_max' => $job->compensation_max,
            'compensation_currency' => $job->compensation_currency,
            'description_completeness' => $job->description_completeness->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDetail(JobPosting $job, PresentGenerationAttempt $presenter, SummarizeInspectionStates $summarizer): array
    {
        // At most one Application per JobPosting — see the
        // applications.job_posting_id unique constraint and
        // App\Support\ApplicationInspection\DispatchApplicationInspection.
        $application = $job->applications()->first();

        return [
            'id' => $job->id,
            'company' => $job->company,
            'title' => $job->title,
            'location' => $job->location,
            'source_url' => $job->source_url,
            'captured_at' => $job->created_at?->toDateString(),
            'description' => $job->description,
            'analyses' => $job->jobAnalyses()
                ->withCount('findings')
                ->latest('generated_at')
                ->get()
                ->map($this->transformAnalysisSummary(...))
                ->all(),
            'latest_job_analysis_attempt' => $this->latestUnresolvedAttempt($job, $presenter),
            'application_id' => $application?->id,
            'inspection' => $summarizer->forApplication($application),
            'discovery' => $this->transformDiscoveryMetadata($job),
            'disposition' => $this->transformDisposition($job),
        ];
    }

    /**
     * The most recent job_analysis GenerationAttempt for this posting,
     * but only when it's still queued/running or ended in failure — a
     * succeeded attempt is deliberately omitted, since its result
     * already appears in `analyses` above and re-surfacing it here
     * would be redundant. Lets the page show "generation in progress"
     * or "here's why the last attempt failed" immediately on load,
     * including after a reload — see docs/job-analysis-generation.md
     * "Async Job Analysis".
     *
     * @return array<string, mixed>|null
     */
    private function latestUnresolvedAttempt(JobPosting $job, PresentGenerationAttempt $presenter): ?array
    {
        /** @var GenerationAttempt|null $attempt */
        $attempt = $job->generationAttempts()
            ->where('generation_type', GenerationType::JobAnalysis)
            ->latest('id')
            ->first();

        // Attempts owned by the one-click pipeline are presented by the
        // Tailored Resume panel on the job page; the legacy per-stage
        // pages must not poll them (and navigate away) or show their
        // errors without the pipeline's stage context.
        if ($attempt === null || $attempt->chain || $attempt->status === GenerationStatus::Succeeded) {
            return null;
        }

        return $presenter->present($attempt);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAnalysisSummary(JobAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'generated_at' => $analysis->generated_at->toDateTimeString(),
            'overall_seniority' => $analysis->overall_seniority?->value,
            'findings_count' => $analysis->findings_count,
        ];
    }
}
