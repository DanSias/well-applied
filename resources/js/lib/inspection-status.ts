import type { Tone } from '@/components/ui/badge';
import type {
    ApplicationInspectionState,
    InspectionSummary,
} from '@/types/application-inspection';

/**
 * Two different questions, kept apart on purpose:
 *
 * - the EXECUTION: did the browser worker's run get queued, run, finish or fail?
 * - the OUTCOME: what did a finished run find (all fields, some, a login wall, an unsupported ATS…)?
 *
 * A run that finishes and finds an unsupported ATS is a worker success with an "Unsupported ATS" outcome. It must never
 * read as a failure of the worker, and it must not read as an inspected form either. Pure, so it can be tested.
 */

export type Labelled = { label: string; tone: Tone };

export type InspectionPresentation = {
    /** A run is queued or running: poll, and block another request. */
    active: boolean;
    execution: Labelled;
    /** What the latest successful run found; null when nothing has ever finished. */
    outcome: (Labelled & { explanation: string }) | null;
    /** That outcome is from an earlier run: the latest attempt failed or was cancelled. */
    outcomeIsEarlier: boolean;
    /** The one-line state of the whole thing, led by the outcome when there is one. */
    headline: Labelled;
    action: 'Inspect application form' | 'Inspect again' | 'Retry inspection';
};

const OUTCOMES: Record<string, Labelled & { explanation: string }> = {
    complete: {
        label: 'Complete',
        tone: 'ok',
        explanation: 'Every field and question on the form was extracted.',
    },
    partial: {
        label: 'Partial',
        tone: 'warn',
        explanation:
            'Some of the form was extracted, but not all of it. Check the warnings below.',
    },
    authentication_required: {
        label: 'Sign-in required',
        tone: 'warn',
        explanation:
            'The form is behind a sign-in. The inspector stops there and never signs in on your behalf.',
    },
    mutation_required: {
        label: 'Needs interaction',
        tone: 'warn',
        explanation:
            'Seeing the rest of the form would mean filling in or submitting something, which the inspector never does.',
    },
    unsupported: {
        label: 'Unsupported ATS',
        tone: 'warn',
        explanation:
            'The inspector ran normally, but this application is not on an applicant tracking system it supports (Greenhouse only for now), so nothing was extracted. The browser worker did not fail.',
    },
};

export function presentInspection(
    state: ApplicationInspectionState | null,
): InspectionPresentation {
    const run = state?.workflow_run ?? null;
    const result = state?.latest_result ?? null;
    const active = run?.status === 'pending' || run?.status === 'running';
    const outcome =
        result?.inspection_outcome != null
            ? (OUTCOMES[result.inspection_outcome] ?? {
                  label: result.inspection_outcome,
                  tone: 'neutral' as Tone,
                  explanation: '',
              })
            : null;
    const outcomeIsEarlier =
        outcome !== null &&
        (run?.status === 'failed' || run?.status === 'cancelled');

    const execution: Labelled = (() => {
        switch (run?.status) {
            case undefined:
                return { label: 'Not run yet', tone: 'neutral' };
            case 'pending':
                return { label: 'Queued', tone: 'accent' };
            case 'running':
                return { label: 'Running', tone: 'accent' };
            case 'succeeded':
                return { label: 'Worker run finished', tone: 'ok' };
            case 'failed':
                return { label: 'Worker run failed', tone: 'bad' };
            case 'cancelled':
                return { label: 'Worker run cancelled', tone: 'neutral' };
        }
    })();

    const headline: Labelled = (() => {
        if (run?.status === 'pending') {
            return { label: 'Inspection queued', tone: 'accent' };
        }

        if (run?.status === 'running') {
            return { label: 'Inspecting…', tone: 'accent' };
        }

        if (run?.status === 'failed' && outcome === null) {
            return { label: 'Inspection failed', tone: 'bad' };
        }

        if (outcome !== null) {
            return {
                label:
                    result?.inspection_outcome === 'complete'
                        ? `Inspected — ${result.field_count} field${result.field_count === 1 ? '' : 's'}`
                        : outcome.label,
                tone: outcome.tone,
            };
        }

        return { label: 'Not inspected yet', tone: 'neutral' };
    })();

    return {
        active,
        execution,
        outcome,
        outcomeIsEarlier,
        headline,
        action:
            run?.status === 'failed' && outcome === null
                ? 'Retry inspection'
                : run === null
                  ? 'Inspect application form'
                  : 'Inspect again',
    };
}

/**
 * The one-line badge for a list row, from the compact summary. Led by what the inspection found, so an outcome with no
 * usable fields never reads as "Inspected": only a complete or partial result counts its questions. Execution state
 * (queued, running, failed) is shown as such. Shares the outcome wording with the drawer.
 */
export function summaryBadge(summary: InspectionSummary): Labelled {
    switch (summary.state) {
        case 'not_inspected':
            return { label: 'Not inspected', tone: 'neutral' };
        case 'queued':
            return { label: 'Inspection queued', tone: 'accent' };
        case 'running':
            return { label: 'Inspecting…', tone: 'accent' };
        case 'failed':
            return { label: 'Inspection failed', tone: 'bad' };
        case 'unsupported':
            return { label: OUTCOMES.unsupported.label, tone: 'warn' };
        case 'inspected': {
            const outcome =
                summary.outcome !== null
                    ? OUTCOMES[summary.outcome]
                    : undefined;
            const n = summary.field_count ?? 0;
            const questions = `${n} question${n === 1 ? '' : 's'}`;

            if (summary.outcome === 'partial') {
                return { label: `Partial · ${questions}`, tone: 'warn' };
            }

            if (outcome !== undefined && summary.outcome !== 'complete') {
                return { label: outcome.label, tone: outcome.tone };
            }

            return n > 0
                ? { label: `Inspected · ${questions}`, tone: 'ok' }
                : { label: 'Nothing extracted', tone: 'warn' };
        }
    }
}
