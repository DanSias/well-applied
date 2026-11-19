import type { Tone } from '@/components/ui/badge';
import type {
    ApplicationInspectionState,
    InspectionSummary,
} from '@/types/application-inspection';

/**
 * Two different questions, kept apart on purpose:
 *
 * - the EXECUTION: did the browser worker's run get queued, run, finish or fail?
 * - the OUTCOME: what did a finished run find (a readable form, a form it can't read yet, no Apply link, a login wall…)?
 *
 * A run that finishes without a form is a worker success with its own outcome. It must never read as a failure of the
 * worker, and it must not read as an inspected form either. Pure, so it can be tested.
 */

export type Labelled = { label: string; tone: Tone };
export type Explained = Labelled & { explanation: string };

export type InspectionPresentation = {
    /** A run is queued or running: poll, and block another request. */
    active: boolean;
    execution: Labelled;
    /** What the latest successful run found; null when nothing has ever finished. */
    outcome: Explained | null;
    /** That outcome is from an earlier run: the latest attempt failed or was cancelled. */
    outcomeIsEarlier: boolean;
    /** The one-line state of the whole thing, led by the outcome when there is one. */
    headline: Labelled;
    action: 'Inspect application form' | 'Inspect again' | 'Retry inspection';
    /** The person's own application link is what is needed next: offer it prominently. */
    needsApplicationUrl: boolean;
};

/** A record from before stop reasons existed: it cannot say why it stopped, and is not reinterpreted. */
const EARLIER_UNSUPPORTED: Explained = {
    label: 'Unsupported (earlier inspection)',
    tone: 'neutral',
    explanation:
        'This result is from before inspections recorded why they stopped, so it cannot say whether an application form was found. Inspect again for a clearer answer.',
};

/**
 * What an outcome means in words, using the stop reason for the specifics. Never says "Unsupported ATS" for a page that
 * simply wasn't an application: that is "No application link found".
 */
export function describeOutcome(
    outcome: string,
    stopReason: string | null,
): Explained {
    switch (outcome) {
        case 'complete':
            return {
                label: 'Complete',
                tone: 'ok',
                explanation: 'Every field and question on the form was read.',
            };
        case 'partial':
            return stopReason === 'no_fields_extracted'
                ? {
                      label: 'Form found, no fields read',
                      tone: 'warn',
                      explanation:
                          'The application form was reached, but no fields could be read from it. It may not have finished loading. Inspect again, or open the posting to check.',
                  }
                : {
                      label: 'Partial',
                      tone: 'warn',
                      explanation:
                          'Some of the form was read, but not all of it. Check the warnings below.',
                  };
        case 'unsupported':
            if (stopReason === 'ats_not_extractable') {
                return {
                    label: 'Form found, not readable yet',
                    tone: 'warn',
                    explanation:
                        'We reached a Lever application form. Reading its fields is not supported yet, so none were listed. The link and the form are fine.',
                };
            }

            if (stopReason === 'form_without_adapter') {
                return {
                    label: 'Form found, not readable yet',
                    tone: 'warn',
                    explanation:
                        'We reached what looks like an application form, but reading forms from this site is not supported yet, so no fields were listed. Nothing is wrong with the link.',
                };
            }

            return EARLIER_UNSUPPORTED;
        case 'application_not_found':
            return {
                label: 'No application link found',
                tone: 'warn',
                explanation:
                    'We opened the job page but could not find an Apply link to follow. Open the posting, copy the link you would apply through, and enter it below.',
            };
        case 'needs_application_url':
            return needsUrl(stopReason);
        case 'authentication_required':
            return {
                label: 'Sign-in required',
                tone: 'warn',
                explanation:
                    'The application is behind a sign-in. The inspector stops there and never signs in on your behalf.',
            };
        case 'blocked':
            return stopReason === 'captcha'
                ? {
                      label: 'Blocked by a CAPTCHA',
                      tone: 'warn',
                      explanation:
                          'The site asked for a CAPTCHA, which the inspector never solves. Open the posting yourself to continue.',
                  }
                : {
                      label: 'Blocked by the site',
                      tone: 'warn',
                      explanation:
                          'The site refused automated access ("access denied" or similar). Nothing was bypassed. Open the posting yourself, or enter the application link if you have a different one.',
                  };
        case 'mutation_required':
            return {
                label: 'Needs interaction',
                tone: 'warn',
                explanation:
                    'Seeing the rest of the form would mean filling in or submitting something, which the inspector never does.',
            };
        default:
            return { label: outcome, tone: 'neutral', explanation: '' };
    }
}

function needsUrl(stopReason: string | null): Explained {
    switch (stopReason) {
        case 'multiple_apply_links':
            return {
                label: 'Several places to apply',
                tone: 'warn',
                explanation:
                    'The page links to more than one place to apply, and we never choose between them. Pick the one you want, copy its link, and enter it below.',
            };
        case 'destination_prohibited':
            return {
                label: 'Destination not allowed',
                tone: 'warn',
                explanation:
                    'The link, or a redirect on the way, pointed somewhere the inspector never goes (a private or internal address, or an unsafe kind of link). Nothing was opened there. Enter the public application link if you have it.',
            };
        case 'destination_unreachable':
            return {
                label: "Destination didn't load",
                tone: 'warn',
                explanation:
                    'The page did not load (an error from the site, or the address could not be found). Check the link, or enter a different one.',
            };
        case 'too_many_redirects':
            return {
                label: 'Too many redirects',
                tone: 'warn',
                explanation:
                    'The link bounced through too many redirects, which usually means a loop. Enter the application link directly if you have it.',
            };
        case 'too_many_steps':
            return {
                label: 'Application not reached',
                tone: 'warn',
                explanation:
                    'We followed the links we are willing to (two) without reaching an application form. Enter the application link directly.',
            };
        default:
            return {
                label: 'Needs an application link',
                tone: 'warn',
                explanation: 'Enter the link you would apply through.',
            };
    }
}

/** Outcomes where the person's own link is the natural next step. */
const URL_NEEDED = new Set(['application_not_found', 'needs_application_url']);

export function presentInspection(
    state: ApplicationInspectionState | null,
): InspectionPresentation {
    const run = state?.workflow_run ?? null;
    const result = state?.latest_result ?? null;
    const active = run?.status === 'pending' || run?.status === 'running';
    const outcome =
        result?.inspection_outcome != null
            ? describeOutcome(result.inspection_outcome, result.stop_reason)
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
        needsApplicationUrl:
            !active &&
            !outcomeIsEarlier &&
            result?.inspection_outcome != null &&
            URL_NEEDED.has(result.inspection_outcome),
    };
}

/**
 * Why a run failed, in words. A failed run is an execution problem (a timeout, a crash, a lapsed claim), never a verdict
 * on the application.
 */
export function describeFailure(
    category: string | null,
    message: string | null,
): string {
    switch (category) {
        case 'navigation_timeout':
            return (
                message ??
                'The inspection ran out of time before it finished. The page may be slow. Try again.'
            );
        case 'claim_timeout':
            return 'The browser worker took the job and never reported back, so it was stopped. Try again.';
        default:
            return message ?? 'The inspection did not finish. Try again.';
    }
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
            // An earlier record with no stop reason: it keeps its own, honest, label.
            return { label: EARLIER_UNSUPPORTED.label, tone: 'neutral' };
        case 'inspected': {
            const outcome =
                summary.outcome !== null
                    ? describeOutcome(summary.outcome, summary.stop_reason)
                    : undefined;
            const n = summary.field_count ?? 0;
            const questions = `${n} question${n === 1 ? '' : 's'}`;

            if (summary.outcome === 'partial' && n > 0) {
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

/**
 * What clicking "Inspect" on an opportunity does: start the first inspection, or just open what is already there. A
 * click that finds an inspection of any kind (running, finished, failed) only opens it; running again is always an
 * explicit action inside the drawer. The server enforces the same rule (`only_if_none`), so a stale list cannot start a
 * second one either.
 */
export function openIntent(
    summary: InspectionSummary,
    sourceUrl: string | null,
): 'start' | 'view' {
    return summary.state === 'not_inspected' && sourceUrl !== null
        ? 'start'
        : 'view';
}
