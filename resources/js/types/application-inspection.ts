/**
 * Matches App\Support\ApplicationInspection\PresentApplicationInspection's
 * shape exactly — the one safe JSON representation used both in
 * ApplicationController::show()'s initial page props and by the
 * polling endpoint. See docs/application-inspector.md.
 */
export type WorkflowRunStatus =
    | 'pending'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export type ApplicationQuestionView = {
    position: number;
    raw_label: string | null;
    label_source: string;
    control_type: string;
    required: boolean | null;
    options: string[] | null;
    section: string | null;
    extraction_source: string;
};

export type InspectionWorkflowRun = {
    id: number;
    status: WorkflowRunStatus;
    failure_message: string | null;
    started_at: string | null;
    finished_at: string | null;
};

/**
 * The technical evidence for one run, for the drawer's collapsed section. Every URL is origin + path only. `recorded` is
 * false for a run from before URL evidence existed: its nulls mean "not recorded", never "nothing happened".
 */
export type InspectionDiagnostics = {
    recorded: boolean;
    requested_url: string | null;
    resolved_url: string | null;
    final_url: string | null;
    page_title: string | null;
    http_status: number | null;
    navigation: { kind: string; url: string | null; status: number | null }[];
    candidates: { url: string | null; text: string | null; kind: string }[];
    warnings: string[];
    start_origin: string | null;
    total_ms: number | null;
    timestamp: string | null;
    failure_message: string | null;
};

export type InspectionResult = {
    agent_run_id: number;
    ats_detected: string | null;
    inspection_outcome: string | null;
    /** Why the inspection stopped where it did; null on a record from before stop reasons existed. */
    stop_reason: string | null;
    finished_at: string | null;
    warnings: string[];
    diagnostics: InspectionDiagnostics;
    field_count: number;
    unresolved_count: number;
    questions: ApplicationQuestionView[];
};

/** Where the application is actually completed, as opposed to where the job was found. Origin + path only. */
export type ApplicationUrlView = {
    url: string | null;
    /** 'user' (entered by a person; never replaced automatically) or 'resolved' (an inspection reached a form there). */
    origin: 'user' | 'resolved' | null;
    set_at: string | null;
};

/** The newest attempt itself, successful or not: why a failed one failed and what it learned first. */
export type InspectionAttempt = {
    agent_run_id: number;
    status: string;
    failure_category: string | null;
    diagnostics: InspectionDiagnostics;
};

export type ApplicationInspectionState = {
    application_id: number;
    application_url: ApplicationUrlView | null;
    workflow_run: InspectionWorkflowRun | null;
    latest_attempt: InspectionAttempt | null;
    latest_result: InspectionResult | null;
    /** An earlier extraction, present only when the latest result found no fields: never hidden by a later empty one. */
    earlier_result: InspectionResult | null;
};

export type ApplicationDetail = {
    id: number;
    job_posting: {
        id: number;
        company: string;
        title: string;
        source_url: string | null;
        /** Where "back to the job" leads: the Opportunity workspace for a pursued job, the job page otherwise. */
        home_url: string;
    };
};

export type ApplicationShowProps = {
    application: ApplicationDetail;
    inspection: ApplicationInspectionState;
};

/**
 * The compact, derived inspection-state summary shown on the
 * Opportunities index/detail — see
 * App\Support\ApplicationInspection\SummarizeInspectionStates. Distinct
 * from ApplicationInspectionState above: this never carries the actual
 * question list, only enough to render a status badge.
 */
export type InspectionState =
    | 'not_inspected'
    | 'queued'
    | 'running'
    | 'inspected'
    | 'failed'
    | 'unsupported';

export type InspectionSummary = {
    state: InspectionState;
    application_id: number | null;
    field_count: number | null;
    latest_attempt_failed: boolean;
    /** What the latest successful run found (complete, partial, unsupported, …); null when nothing has finished. */
    outcome: string | null;
    /** Why it stopped there; null for a run from before stop reasons existed. */
    stop_reason: string | null;
};

/** What the Opportunities inspection drawer is handed for one pursued job (OpportunityController::inspectionJob). */
export type InspectionJob = {
    id: number;
    title: string;
    company: string;
    source_url: string | null;
    application_id: number | null;
    inspection: ApplicationInspectionState | null;
};
