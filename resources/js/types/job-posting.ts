import type { InspectionSummary } from '@/types/application-inspection';
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
 * /jobs summary item. `coverage` is non-null only in `matched`; the
 * `*_at` fields are each snapshot's own generated_at (or null).
 */
export type OpportunityTriage = {
    stage: OpportunityTriageStage;
    coverage: OpportunityTriageCoverage | null;
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
};

export type JobsIndexProps = {
    jobs: JobPostingSummary[];
    filters: JobsIndexFilters;
};

export type JobShowProps = {
    job: JobPostingDetail;
};
