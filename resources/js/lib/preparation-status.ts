import type { Tone } from '@/components/ui/badge';
import type {
    PreparationAttemptRow,
    PreparationOutcome,
    PreparationPhase,
    PreparationState,
} from '@/types/application-preparation';

/**
 * Three different questions, kept apart on purpose (as inspection-status.ts keeps two apart):
 *
 * - the EXECUTION: was the attempt queued, running, finished, failed or cancelled?
 * - the OUTCOME: what did a finished attempt find (needs answers, blocked, a safety stop…)?
 * - SUBMISSION: nothing here ever submits. No label in this file says "submitted", "sent" or "applied", and a finished
 *   preparation is only ever "ready for review".
 *
 * A delivered "needs your input" report is a successful run, not a failure; a safety stop is never "ready". Pure, so it
 * can be tested.
 */
export type Labelled = { label: string; tone: Tone };

export type PreparationPresentation = {
    /** Queued or running: poll, and block another start. */
    active: boolean;
    /** Only a queued attempt can be cancelled; once a worker has it, it cannot. */
    canCancel: boolean;
    kind:
        | 'none'
        | 'queued'
        | 'running'
        | 'ready'
        | 'input'
        | 'blocked'
        | 'unsupported'
        | 'safety'
        | 'check'
        | 'failed'
        | 'cancelled';
    headline: Labelled;
    explanation: string;
    /** What the primary button says when a (new) attempt may be started. */
    startLabel: 'Prepare application' | 'Prepare again' | 'Start a new attempt';
    /** The latest attempt delivered a report that can be looked at. */
    hasReview: boolean;
};

const PHASES: Record<PreparationPhase, string> = {
    opening: 'Opening the application form',
    filling: 'Filling in your details',
    uploading: 'Attaching your resume',
    verifying: 'Checking what was filled in',
    capturing: 'Capturing screenshots',
    delivering: 'Sending results back',
};

export function phaseLabel(phase: PreparationPhase | null): string {
    return phase === null ? 'Working on the form' : PHASES[phase];
}

const FAILURES: Record<string, string> = {
    claim_timeout:
        'The preparation worker stopped responding before it finished, so the attempt was closed. Nothing was submitted, and it is not retried automatically.',
    navigation_timeout:
        'The application form did not finish loading in time. Nothing was submitted.',
    unsupported_ats:
        'The worker did not recognise this application site. Nothing was submitted.',
    unexpected_error:
        'The worker hit an unexpected problem. Nothing was submitted, and it is not retried automatically.',
};

export function failureExplanation(category: string | null): string {
    return (
        (category !== null ? FAILURES[category] : undefined) ??
        'The attempt failed. Nothing was submitted, and it is not retried automatically.'
    );
}

const OUTCOMES: Record<
    PreparationOutcome,
    {
        kind: PreparationPresentation['kind'];
        label: Labelled;
        explanation: string;
    }
> = {
    ready_for_review: {
        kind: 'ready',
        label: { label: 'Ready for review', tone: 'ok' },
        explanation:
            'The form was filled in with what you approved and every required field was verified. Look it over: it has not been submitted.',
    },
    input_required: {
        kind: 'input',
        label: { label: 'Needs your input', tone: 'warn' },
        explanation:
            'The form was filled in as far as possible. Some required questions still need an answer from you, or an action only a person can take. It has not been submitted.',
    },
    blocked: {
        kind: 'blocked',
        label: { label: 'Blocked', tone: 'warn' },
        explanation:
            'A person has to act before this form can go further (for example a sign-in, a CAPTCHA, a multi-step form, or a resume upload that could not be verified). It has not been submitted.',
    },
    unsupported: {
        kind: 'unsupported',
        label: { label: 'Not supported', tone: 'warn' },
        explanation:
            'This form is not one the preparation worker can handle. The worker itself did not fail. Nothing was submitted.',
    },
    safety_failure: {
        kind: 'safety',
        label: { label: 'Stopped for safety', tone: 'bad' },
        explanation:
            'Something happened that looked like a submission or an unexpected side effect, so the attempt was stopped and is not ready for anything. Check the events below.',
    },
};

const NONE: PreparationPresentation = {
    active: false,
    canCancel: false,
    kind: 'none',
    headline: { label: 'Not started', tone: 'neutral' },
    explanation:
        'Preparation fills in the application form with your approved details and attaches your resume, then stops. It never submits anything.',
    startLabel: 'Prepare application',
    hasReview: false,
};

export function presentPreparation(
    state: PreparationState | null,
): PreparationPresentation {
    const attempt = state?.attempt ?? null;

    if (state === null || attempt === null) {
        return NONE;
    }

    const again = { startLabel: 'Prepare again' as const };

    switch (attempt.status) {
        case 'queued':
            return {
                active: true,
                canCancel: true,
                kind: 'queued',
                headline: { label: 'Queued', tone: 'accent' },
                explanation:
                    'Waiting for the preparation worker to pick it up. You can cancel it until it starts.',
                startLabel: 'Prepare application',
                hasReview: false,
            };
        case 'running':
            return {
                active: true,
                canCancel: false,
                kind: 'running',
                headline: { label: 'Running', tone: 'accent' },
                explanation: `${attempt.phase === null ? 'The worker has started.' : `${phaseLabel(attempt.phase)}.`} This cannot be cancelled once the worker has started.`,
                startLabel: 'Prepare application',
                hasReview: false,
            };
        case 'cancelled':
            return {
                ...NONE,
                kind: 'cancelled',
                headline: { label: 'Cancelled', tone: 'neutral' },
                explanation:
                    'You cancelled this attempt before it started. Nothing was prepared and nothing was submitted.',
                startLabel: 'Start a new attempt',
            };
        case 'failed':
            return {
                ...NONE,
                kind: 'failed',
                headline: { label: 'Failed', tone: 'bad' },
                explanation: failureExplanation(attempt.failure_category),
                startLabel: 'Start a new attempt',
            };
        case 'succeeded': {
            const result = state.latest_result;
            const outcome =
                result?.agent_run_id === attempt.agent_run_id
                    ? result.outcome
                    : null;
            const known = outcome === null ? null : OUTCOMES[outcome];

            if (known === null || result === null) {
                return {
                    ...NONE,
                    kind: 'check',
                    headline: { label: 'Finished', tone: 'neutral' },
                    explanation:
                        'The worker finished. Its report could not be read here. Nothing was submitted.',
                    startLabel: 'Start a new attempt',
                };
            }

            // A report whose own evidence does not hold together is not something to review as prepared.
            if (
                !result.reviewable &&
                outcome !== 'safety_failure' &&
                outcome !== 'unsupported'
            ) {
                return {
                    active: false,
                    canCancel: false,
                    kind: 'check',
                    headline: { label: 'Check the evidence', tone: 'warn' },
                    explanation:
                        'The worker finished, but its evidence did not match what it was given (for example the resume). Treat this attempt as unreliable. Nothing was submitted.',
                    startLabel: again.startLabel,
                    hasReview: true,
                };
            }

            return {
                active: false,
                canCancel: false,
                kind: known.kind,
                headline: known.label,
                explanation: known.explanation,
                startLabel: again.startLabel,
                hasReview: outcome !== 'unsupported',
            };
        }
    }
}

/** One earlier attempt as a badge, on its own, without borrowing the latest attempt's result. */
export function presentAttempt(row: PreparationAttemptRow): Labelled {
    switch (row.status) {
        case 'queued':
            return { label: 'Queued', tone: 'accent' };
        case 'running':
            return { label: 'Running', tone: 'accent' };
        case 'cancelled':
            return { label: 'Cancelled', tone: 'neutral' };
        case 'failed':
            return { label: 'Failed', tone: 'bad' };
        case 'succeeded': {
            if (row.outcome === null) {
                return { label: 'Finished', tone: 'neutral' };
            }

            if (
                !row.reviewable &&
                row.outcome !== 'safety_failure' &&
                row.outcome !== 'unsupported'
            ) {
                return { label: 'Check the evidence', tone: 'warn' };
            }

            return OUTCOMES[row.outcome].label;
        }
    }
}

/** Whether a state is one that still changes by itself, so polling should continue. */
export function isActiveState(state: PreparationState | null): boolean {
    return presentPreparation(state).active;
}

const UPLOAD: Record<string, string> = {
    accepted_filename_visible:
        'The site accepted the upload and the file name appears on the form.',
    accepted_confirmation_missing:
        'The site accepted the upload, but the file name did not appear on the form, so it is not verified.',
    failed: 'The upload was refused or failed, so it is not verified.',
    unknown:
        'The upload was sent but no answer came back in time, so it is not verified.',
    no_upload_request:
        'The file was selected but the page did not send it, so it is not verified.',
    selection_failed: 'The resume could not be attached to the form correctly.',
    unauthorized_request:
        'An upload-like request was refused for safety. Nothing was sent.',
    not_applicable:
        'This site attaches the file to the form itself; it was checked by file hash.',
};

export function uploadExplanation(outcome: string | null | undefined): string {
    return (
        (outcome ? UPLOAD[outcome] : undefined) ??
        'No upload evidence was reported for the resume.'
    );
}

export const UNLABELLED = 'Unlabelled question (the form gave it no label)';
