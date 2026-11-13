import type { InspectionSummary } from '@/types/application-inspection';
import type {
    ApprovedEvidence,
    TailoredResumeStatus,
    TailoringProfile,
} from '@/types/tailored-resume';
import type { GenerationAttempt } from '@/types/generation-attempt';

/**
 * Matches JobPostingController::transformDiscoveryMetadata() exactly.
 * source is always present ('manual' for hand-created postings);
 * everything else is null unless discovery/canonical enrichment
 * populated it.
 */
export type DescriptionCompleteness = 'complete' | 'preview' | 'unknown';

export type JobPostingDiscoveryMetadata = {
    source: string;
    canonical_source: string | null;
    discovered_at: string | null;
    remote_status: string | null;
    employment_type: string | null;
    compensation_min: number | null;
    compensation_max: number | null;
    compensation_currency: string | null;
    description_completeness: DescriptionCompleteness;
};

/**
 * Position of a posting in the Opportunity Triage pipeline. A workflow
 * state derived live from the durable generation history — never a measure
 * of fit, desirability, or recommendation. Mirrors the backend's Stage
 * precedence (raw → analyzing → analyzed → matching → matched), with the
 * failed arms (analysis-failed, match-failed) when a step errored.
 */
export type OpportunityTriageStage =
    | 'raw'
    | 'analyzing'
    | 'analysis-failed'
    | 'analyzed'
    | 'matching'
    | 'match-failed'
    | 'matched';

/**
 * Factual counts of a matched opportunity's findings by their holistic
 * evidence classification. Present (zero-filled) only in the `matched`
 * stage. These are availability counts, NOT a fit score, percentage, rank
 * or recommendation — never compute or present any of those from them.
 */
export type OpportunityTriageCoverage = {
    supported: number;
    partial: number;
    no_evidence: number;
    not_assessable: number;
};

/**
 * Matches SummarizeTriageStates::summary() exactly. Present on every
 * /jobs summary item. `coverage` is non-null only in `matched`;
 * `analysis_id` is the selected JobAnalysis snapshot's id (null when the
 * stage has no selected snapshot); the `*_at` fields are each snapshot's
 * own generated_at (or null).
 */
export type OpportunityTriage = {
    stage: OpportunityTriageStage;
    coverage: OpportunityTriageCoverage | null;
    analysis_id: number | null;
    analysis_at: string | null;
    match_at: string | null;
};

export type JobPostingSummary = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    has_source_url: boolean;
    source_url: string | null;
    captured_at: string | null;
    inspection: InspectionSummary;
    triage: OpportunityTriage;
    discovery: JobPostingDiscoveryMetadata;
    disposition: JobDispositionSummary;
};

export type JobDisposition = 'new' | 'shortlisted' | 'dismissed' | 'pursued';

/** A job's decision state, with the reason when it was dismissed. */
export type JobDispositionSummary = {
    value: JobDisposition;
    reason: string | null;
    reason_label: string | null;
    decided_at: string | null;
};

/** Which decision view of the Jobs page: the inbox (undecided), the shortlist, dismissed jobs, or everything. */
export type JobDispositionFilter = 'inbox' | 'shortlist' | 'dismissed' | 'all';

export type DismissalReason =
    | 'seniority'
    | 'technology_stack'
    | 'location'
    | 'compensation'
    | 'company'
    | 'duplicate'
    | 'other';

/**
 * What a decision did, flashed under `decision_result` by JobDecisionController: which jobs changed, which were
 * already in that state, which were refused (with a safe message) or not found, and the decision ids to undo them.
 */
export type DecisionResult = {
    to: JobDisposition | 'undo';
    changed: number[];
    unchanged: number[];
    failed: { id: number; message: string }[];
    not_found: number[];
    decision_ids: Record<string, number>;
};

export type JobAnalysisSummary = {
    id: number;
    generated_at: string | null;
    overall_seniority: string | null;
    findings_count: number | null;
};

export type JobPostingDetail = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    source_url: string | null;
    captured_at: string | null;
    description: string;
    analyses: JobAnalysisSummary[];
    latest_job_analysis_attempt: GenerationAttempt | null;
    application_id: number | null;
    inspection: InspectionSummary;
    discovery: JobPostingDiscoveryMetadata;
    disposition: JobDispositionSummary;
};

/**
 * Triage-stage filter groups for the /jobs index. A deterministic UI
 * grouping over the seven canonical OpportunityTriageStage values (each
 * group maps to one or more stages on the backend) — a navigation filter,
 * not a score, rank, or recommendation.
 */
export type OpportunityTriageFilter =
    | 'all'
    | 'raw'
    | 'in-progress'
    | 'analyzed'
    | 'matched'
    | 'failed';

export type JobsIndexFilters = {
    q: string;
    status: 'all' | 'not_inspected' | 'inspected';
    triage: OpportunityTriageFilter;
    resume: OpportunityResumeFilter;
    disposition: JobDispositionFilter;
};

/** Resume work per opportunity: a resume exists but none is final, or one is marked final. */
export type OpportunityResumeFilter = 'all' | 'in-progress' | 'final';

export type JobsIndexPagination = {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number;
    to: number;
    per_page_options: number[];
};

export type JobsIndexProps = {
    jobs: JobPostingSummary[];
    filters: JobsIndexFilters;
    pagination: JobsIndexPagination;
    disposition_counts: Record<JobDispositionFilter, number>;
};

export type JobShowProps = {
    job: JobPostingDetail;
    tailored_resume: TailoredResumeStatus;
    resume_profiles: TailoringProfile[];
    approved_evidence: ApprovedEvidence;
};

/**
 * One human-initiated batch operation over /jobs. Mirrors the two
 * endpoints JobBatchController exposes and the `operation` field of the
 * batch result payload.
 */
export type BatchOperation = 'analyze' | 'match';

/**
 * Outcome of a single item in a batch result. Mirrors the fixed outcome
 * set ProcessBatchOpportunities::run() reports for each requested id.
 */
export type BatchOutcome = 'started' | 'skipped' | 'failed' | 'not_found';

/**
 * One requested id's result. `reason` is null except for `skipped` and
 * `failed`, where it is one of the backend's safe, small reason literals
 * (`in_flight`, `claim_failed`, `analysis_unavailable`).
 */
export type BatchItem = {
    job_id: number;
    outcome: BatchOutcome;
    reason: string | null;
};

/**
 * Matches JobBatchController's batch result exactly, as flashed to the
 * page under `batch_result` (Inertia flash data, one-time). Counts and
 * items always agree: items are in requested order and the counts sum to
 * `requested`.
 */
export type BatchResult = {
    operation: BatchOperation;
    requested: number;
    started: number;
    skipped: number;
    failed: number;
    not_found: number;
    items: BatchItem[];
};
