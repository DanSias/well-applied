import type {
    ApplicationQuestionView,
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
    start_origin: null,
    blocked_requests: 0,
    http_status: null,
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

/**
 * The reference case for "the inspector ran but the application was not located": Jobicy job 348, agent run 11. The listing
 * answered 200, had no Apply link (only a JavaScript Apply button), and the page sent 18 non-GET requests that the
 * read-only guard blocked. Technically a successful run; the application was simply not found.
 */
export const jobicyNoApplyLinkState = (
    over: Partial<ApplicationInspectionState> = {},
): ApplicationInspectionState => {
    const url = 'https://jobicy.com/jobs/162959-software-integration-engineer';
    const warnings = [
        ...Array.from({ length: 18 }, () => 'blocked_non_get'),
        'apply_is_button',
    ];

    return stateOf(
        runOf('succeeded', { id: 11 }),
        resultOf('application_not_found', {
            agent_run_id: 11,
            stop_reason: 'no_apply_link',
            finished_at: '2026-11-20T12:51:46Z',
            warnings,
            diagnostics: diagnosticsOf({
                requested_url: url,
                final_url: url,
                resolved_url: null,
                page_title:
                    'Remote Software Integration Engineer at PlusAI - Jobicy',
                navigation: [{ kind: 'start', url, status: 200 }],
                warnings,
                start_origin: 'source',
            }),
        }),
        over,
    );
};

/**
 * The first live generic-discovery run (NerdWallet job 258, Application 10, agent run 13): a link the person entered, an Ashby
 * page that loaded (HTTP 200) but whose form never rendered because the two requests it makes to load it send data, which a
 * read-only inspection blocks. Stored as application_not_found / no_apply_link with `blocked_non_get` twice.
 */
export const ashbyBlockedFormState = (
    over: Partial<ApplicationInspectionState> = {},
): ApplicationInspectionState => {
    const url =
        'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application';
    const warnings = ['blocked_non_get', 'blocked_non_get'];

    return stateOf(
        runOf('succeeded', { id: 13 }),
        resultOf('application_not_found', {
            agent_run_id: 13,
            stop_reason: 'no_apply_link',
            finished_at: '2026-11-20T14:36:48Z',
            warnings,
            diagnostics: diagnosticsOf({
                requested_url: url,
                final_url: url,
                resolved_url: null,
                page_title: 'Software Engineer, AI & Automation @ NerdWallet',
                http_status: 200,
                navigation: [{ kind: 'start', url, status: 200 }],
                candidates: [],
                warnings,
                start_origin: 'user',
            }),
        }),
        {
            application_url: {
                url,
                origin: 'user',
                set_at: '2026-11-20T14:36:43Z',
            },
            ...over,
        },
    );
};

/**
 * A schema-only Ashby result as the worker now reports it (flag BROWSER_WORKER_ASHBY_SCHEMA): fields from the provider's own
 * form schema, `partial`, no URL claims. Three fields stand in for the 29 of the NerdWallet job: a required text field, an
 * optional survey select, and an untitled consent control.
 */
export const ashbySchemaOnlyState = (
    over: Partial<ApplicationInspectionState> = {},
): ApplicationInspectionState => {
    const url =
        'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application';
    const warnings = [
        'provider_schema_only',
        'visibility_not_established',
        'conditional_logic_not_in_schema',
        'unlabelled_control',
        'provider_schema_application_fields:2',
        'provider_schema_survey_fields:1',
    ];
    const q = (
        position: number,
        over: Partial<ApplicationQuestionView>,
    ): ApplicationQuestionView => ({
        position,
        raw_label: 'Name',
        label_source: 'provider_schema',
        control_type: 'text',
        required: true,
        options: null,
        section: 'Personal Information',
        extraction_source: 'provider_schema',
        ...over,
    });

    return stateOf(
        runOf('succeeded', { id: 14 }),
        resultOf('partial', {
            agent_run_id: 14,
            ats_detected: 'ashby',
            finished_at: '2026-11-21T10:00:00Z',
            warnings,
            field_count: 3,
            unresolved_count: 1,
            diagnostics: diagnosticsOf({
                requested_url: url,
                final_url: null,
                resolved_url: null,
                http_status: null,
                navigation: [],
                warnings,
                start_origin: 'user',
            }),
            questions: [
                q(0, {}),
                q(1, {
                    raw_label: 'Gender',
                    control_type: 'select',
                    required: false,
                    options: ['Female', 'Male'],
                    section: 'Demographic Survey',
                }),
                q(2, {
                    raw_label: null,
                    label_source: 'unresolved',
                    control_type: 'select',
                    required: false,
                    options: ['I agree'],
                    section: null,
                }),
            ],
        }),
        {
            application_url: { url, origin: 'user', set_at: null },
            ...over,
        },
    );
};
