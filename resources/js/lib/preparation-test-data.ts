import type {
    PreparationAttemptRow,
    PreparationPanel,
    PreparationReview,
    PreparationState,
    PreparationExecution,
    PreparationOutcome,
} from '@/types/application-preparation';

/** Synthetic fixtures for the preparation UI tests. No real applicant data. */
export const attemptRow = (
    over: Partial<PreparationAttemptRow> = {},
): PreparationAttemptRow => ({
    agent_run_id: 1,
    workflow_run_id: 10,
    status: 'succeeded',
    failure_category: null,
    outcome: 'input_required',
    reviewable: true,
    queued_at: '2026-11-18T10:00:00Z',
    finished_at: '2026-11-18T10:01:00Z',
    resume_variant_id: 7,
    resume_sha256: 'a'.repeat(64),
    ...over,
});

export function stateOf(
    status: PreparationExecution,
    over: {
        outcome?: PreparationOutcome | null;
        reviewable?: boolean;
        failure?: string | null;
        phase?: PreparationState['attempt'] extends infer A
            ? A extends { phase: infer P }
                ? P
                : never
            : never;
        id?: number;
        attempts?: PreparationAttemptRow[];
    } = {},
): PreparationState {
    const id = over.id ?? 1;
    const outcome =
        over.outcome === undefined ? 'input_required' : over.outcome;

    return {
        application_id: 5,
        workflow_run: {
            id: 10 + id,
            status: status === 'queued' ? 'pending' : status,
            failure_message: null,
            started_at: null,
            finished_at: null,
        },
        attempt: {
            agent_run_id: id,
            status,
            queued_at: '2026-11-18T10:00:00Z',
            started_at: status === 'queued' ? null : '2026-11-18T10:00:30Z',
            phase: over.phase ?? null,
            failure_category: over.failure ?? null,
            inputs: null,
        },
        attempts: over.attempts ?? [
            attemptRow({
                agent_run_id: id,
                workflow_run_id: 10 + id,
                status,
                outcome: status === 'succeeded' ? outcome : null,
            }),
        ],
        latest_result:
            status === 'succeeded' && outcome !== null
                ? {
                      agent_run_id: id,
                      finished_at: null,
                      outcome,
                      reviewable: over.reviewable ?? true,
                      integrity_flags: [],
                      counts: { filledVerified: 5 },
                  }
                : null,
    };
}

export const panelOf = (
    state: PreparationState | null,
    over: Partial<PreparationPanel> = {},
): PreparationPanel => ({
    job_id: 3,
    can_prepare:
        state === null ||
        !['queued', 'running'].includes(state.attempt?.status ?? ''),
    problem: null,
    destination: {
        host: 'job-boards.greenhouse.io',
        path: '/example/jobs/123456',
    },
    resumes: {
        default_id: 7,
        default_kind: 'final',
        options: [
            {
                id: 7,
                label: 'Generated resume 1',
                summary: 'Written from your approved profile',
                generated_at: '2026-11-17T10:00:00Z',
                is_final: true,
                is_current: true,
            },
            {
                id: 8,
                label: 'Revision of resume 1',
                summary: 'edited the summary',
                generated_at: '2026-11-17T12:00:00Z',
                is_final: false,
                is_current: false,
            },
        ],
    },
    state,
    ...over,
});

export const reviewOf = (
    over: Record<string, unknown> = {},
): PreparationReview =>
    ({
        agent_run_id: 1,
        status: 'succeeded',
        submission: {
            occurred: false,
            statement:
                'Nothing was submitted. This is a prepared form for you to review; sending it is a separate step that has not happened.',
        },
        resume: {
            variant_id: 7,
            sha256: 'a'.repeat(64),
            bytes: 630,
            verified: true,
            verification: 'upload_accepted',
            reported_sha256_matches: true,
            upload: {
                outcome: 'accepted_filename_visible',
                http_status: 201,
                filename_visible: true,
                detail: null,
            },
        },
        available: true,
        evidence: { observed_at: 'preparation', final_state_captured: false },
        outcome: 'input_required',
        reported_outcome: 'input_required',
        reviewable: true,
        integrity_flags: [],
        ats: 'greenhouse',
        reasons: ['unresolved_required: Why do you want to join?'],
        warnings: [],
        counts: { filledVerified: 5 },
        question_summary: {},
        verified: [
            {
                label: 'First Name',
                required: true,
                kind: 'text',
                status: 'filled_verified',
                review: 'complete',
                sensitive: false,
                provenance: { source: 'applicant_fact', key: 'first_name' },
                entered: 'Alex',
                intended: null,
                page_selected: [],
                note: null,
            },
        ],
        needs_answer: [
            {
                label: 'Why do you want to join Example?',
                required: true,
                kind: 'textarea',
                status: 'unresolved_required',
                review: 'needs_answer',
                sensitive: false,
                provenance: { source: 'none', key: null },
                entered: null,
                intended: null,
                page_selected: [],
                note: null,
            },
        ],
        optional_skipped: [
            {
                label: null,
                required: false,
                kind: 'select',
                status: 'unanswered_optional',
                review: 'optional_skipped',
                sensitive: false,
                provenance: { source: 'none', key: null },
                entered: null,
                intended: null,
                page_selected: ['Email'],
                note: null,
            },
        ],
        needs_person: [
            {
                label: 'Location (City)',
                required: true,
                kind: 'text',
                status: 'manual_action_required',
                review: 'needs_person',
                sensitive: false,
                provenance: { source: 'applicant_fact', key: 'location' },
                entered: null,
                intended: 'Springfield, USA',
                page_selected: [],
                note: 'typeahead: a suggestion must be selected by a person',
            },
        ],
        needs_check: [],
        failed: [],
        safety_events: [
            {
                kind: 'blocked_telemetry_request',
                detail: 'greenhouse snowplow analytics beacon',
                failure: false,
            },
        ],
        blockers: { captcha: 'inactive_markup' },
        screenshots: [
            {
                file: 'before-01of02-y00000.png',
                phase: 'before',
                index: 1,
                of: 2,
                stored: true,
                url: '/preparation-attempts/1/screenshots/before-01of02-y00000.png',
            },
            {
                file: 'after_fill-01of02-y00000.png',
                phase: 'after_fill',
                index: 1,
                of: 2,
                stored: false,
                url: null,
            },
        ],
        ...over,
    }) as PreparationReview;
