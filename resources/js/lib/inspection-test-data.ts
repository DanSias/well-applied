import type {
    ApplicationInspectionState,
    InspectionDiagnostics,
    InspectionJob,
    InspectionResult,
    InspectionSummary,
    InspectionWorkflowRun,
} from '@/types/application-inspection';

/** Builders for inspection state in tests; every field a person sees can be overridden. */
export const diagnosticsOf = (
    over: Partial<InspectionDiagnostics> = {},
): InspectionDiagnostics => ({
    recorded: true,
    requested_url: 'https://jobicy.com/jobs/1',
    resolved_url: null,
    final_url: 'https://jobicy.com/jobs/1',
    page_title: 'Platform Engineer - Jobicy',
    http_status: 200,
    navigation: [
        { kind: 'start', url: 'https://jobicy.com/jobs/1', status: 200 },
    ],
    candidates: [],
    warnings: [],
    start_origin: 'source',
    total_ms: 4200,
    timestamp: null,
    failure_message: null,
    ...over,
});

export const runOf = (
    status: InspectionWorkflowRun['status'],
    over: Partial<InspectionWorkflowRun> = {},
): InspectionWorkflowRun => ({
    id: 1,
    status,
    failure_message: null,
    started_at: null,
    finished_at: null,
    ...over,
});

export const resultOf = (
    outcome: string,
    over: Partial<InspectionResult> = {},
): InspectionResult => ({
    agent_run_id: 1,
    ats_detected: null,
    inspection_outcome: outcome,
    stop_reason: null,
    finished_at: null,
    warnings: [],
    diagnostics: diagnosticsOf(),
    field_count: 0,
    unresolved_count: 0,
    questions: [],
    ...over,
});

export const stateOf = (
    workflow_run: InspectionWorkflowRun | null,
    latest_result: InspectionResult | null = null,
    over: Partial<ApplicationInspectionState> = {},
): ApplicationInspectionState => ({
    application_id: 1,
    application_url: null,
    workflow_run,
    latest_attempt: null,
    latest_result,
    earlier_result: null,
    ...over,
});

export const summaryOf = (
    state: InspectionSummary['state'],
    outcome: string | null = null,
    field_count: number | null = null,
    stop_reason: string | null = null,
): InspectionSummary => ({
    state,
    application_id: state === 'not_inspected' ? null : 1,
    field_count,
    latest_attempt_failed: false,
    outcome,
    stop_reason,
});

export const jobOf = (
    inspection: ApplicationInspectionState | null,
    over: Partial<InspectionJob> = {},
): InspectionJob => ({
    id: 7,
    title: 'Platform Engineer',
    company: 'Acme',
    source_url: 'https://jobicy.com/jobs/1',
    application_id: inspection === null ? null : 1,
    inspection,
    ...over,
});
