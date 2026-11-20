import type { Tone } from '@/components/ui/badge';
import type { StartOptions } from '@/hooks/use-start-inspection';
import type {
    ApplicationInspectionState,
    InspectionResult,
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
    /** What the wording was allowed to know, so every surface words the same result the same way. */
    context: OutcomeContext;
};

/** A record from before stop reasons existed: it cannot say why it stopped, and is not reinterpreted. */
const EARLIER_UNSUPPORTED: Explained = {
    label: 'Unsupported (earlier inspection)',
    tone: 'neutral',
    explanation:
        'This result is from before inspections recorded why they stopped, so it cannot say whether an application form was found. Inspect again for a clearer answer.',
};

/**
 * What the wording may mention about where the inspection looked and what it saw. Never required: absent, the wording stays
 * generic. Every field is evidence from the stored result, and the wording claims no more than the evidence supports.
 */
export type OutcomeContext = {
    /** The host of the page the inspection started from, e.g. "jobicy.com". */
    listingHost?: string | null;
    /** Where the start URL came from: 'user' (a link the person entered), 'resolved', 'provider', 'source' (the job listing). */
    startOrigin?: string | null;
    /** How many of the page's own requests that send data (POST and the like) read-only inspection refused. */
    blockedRequests?: number;
    /** The HTTP status of the page that was opened. */
    httpStatus?: number | null;
};

/** The person gave this link themselves, so the page it leads to is, by their account, the application. */
const enteredByUser = (context: OutcomeContext) =>
    context.startOrigin === 'user';
/** The page loaded, and it tried to send data that read-only inspection refuses: the likeliest reason a form did not appear. */
const loadedButBlocked = (context: OutcomeContext) =>
    enteredByUser(context) &&
    context.httpStatus === 200 &&
    (context.blockedRequests ?? 0) > 0;

/** The host of a URL (origin + path, as the server hands it over), or null when it is missing or not a URL. */
export function hostOf(url: string | null | undefined): string | null {
    if (!url) {
        return null;
    }

    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return null;
    }
}

/**
 * Why the inspection stopped, as a sentence. This is the specific detail behind the outcome label, so it says what was
 * observed, not what to do (the outcome's explanation does that). Null for a record from before stop reasons existed:
 * it is never guessed.
 */
export function describeStopReason(
    stopReason: string | null,
    context: OutcomeContext = {},
): string | null {
    switch (stopReason) {
        case 'no_apply_link':
            // For a link the person entered there is no listing to follow a link from: what stopped it is that no form appeared.
            if (loadedButBlocked(context)) {
                const n = context.blockedRequests ?? 0;

                return `The page loaded but no application form appeared. ${n} of its own request${n === 1 ? '' : 's'} that send data ${n === 1 ? 'was' : 'were'} blocked, as every read-only inspection blocks them.`;
            }
            if (enteredByUser(context)) {
                return 'No application form was found on the page you entered.';
            }

            return 'The page has no link to an application form that could be followed without clicking.';
        case 'multiple_apply_links':
            return 'The page links to more than one place to apply, and the inspector never chooses between them.';
        case 'destination_prohibited':
            return 'A link or redirect led somewhere the inspector never goes, so nothing was opened there.';
        case 'destination_unreachable':
            return 'The destination did not load.';
        case 'too_many_redirects':
            return 'The link redirected too many times.';
        case 'too_many_steps':
            return 'The page and link limit was used up before an application form was reached.';
        case 'login_wall':
            return 'The page is behind a sign-in, which the inspector never completes.';
        case 'captcha':
            return 'The site asked for a CAPTCHA, which the inspector never solves.';
        case 'anti_bot':
            return 'The site refused automated access.';
        case 'form_without_adapter':
            return 'An application form was reached, but reading forms from this site is not supported yet.';
        case 'ats_not_extractable':
            return 'A recognised application system was reached, but reading its forms is not supported yet.';
        case 'no_fields_extracted':
            return 'A form was reached, but no fields could be read from it.';
        default:
            return null;
    }
}

/** Where the inspection began, in words. Shared by every inspection view so they cannot disagree. */
export const START_ORIGIN: Record<string, string> = {
    user: 'The link you entered',
    resolved: 'The link an earlier inspection found',
    provider: 'The job source’s application link',
    source: 'The job listing',
};

/** How a saved application link got there, in words. */
export function describeLinkOrigin(origin: string | null): string {
    return origin === 'user'
        ? 'you entered this'
        : origin === 'resolved'
          ? 'found by an inspection'
          : 'origin not recorded';
}

/**
 * What a warning code means, for the few worth saying out loud in the main view. Every code, known or not, is still listed
 * (grouped) in the technical details.
 */
const WARNING_TEXT: Record<string, string> = {
    apply_is_button:
        'This listing uses an Apply button rather than a link. The inspector never presses buttons, so it could not safely follow it automatically.',
    apply_is_email:
        'The page applies by email, which is not something the inspector opens.',
    destination_is_download:
        'The Apply link leads to a file, not an application form.',
};

export function describeWarning(code: string): string | null {
    return WARNING_TEXT[code] ?? null;
}

/** The warnings worth saying out loud: each distinct code once, in the order first seen. */
export function highlightedWarnings(
    warnings: string[],
): { code: string; text: string }[] {
    return groupWarnings(warnings).flatMap(({ code }) => {
        const text = describeWarning(code);

        return text === null ? [] : [{ code, text }];
    });
}

export type WarningGroup = { code: string; count: number };

/**
 * One entry per distinct warning, in the order first seen, with how many times it occurred. A page can report the same
 * code dozens of times (every blocked request); the evidence stays in the stored result, and the view shows the code once
 * with its count.
 */
export function groupWarnings(warnings: string[]): WarningGroup[] {
    const counts = new Map<string, number>();

    for (const warning of warnings) {
        counts.set(warning, (counts.get(warning) ?? 0) + 1);
    }

    return [...counts].map(([code, count]) => ({ code, count }));
}

/**
 * What an outcome means in words, using the stop reason for the specifics. Never says "Unsupported ATS" for a page that
 * simply wasn't an application: that is "No application link found".
 */
export function describeOutcome(
    outcome: string,
    stopReason: string | null,
    context: OutcomeContext = {},
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
            if (loadedButBlocked(context)) {
                const n = context.blockedRequests ?? 0;

                return {
                    label: 'Page loaded, form unavailable',
                    tone: 'warn',
                    explanation: `We opened the application link you entered${
                        context.listingHost ? ` (${context.listingHost})` : ''
                    } and it loaded, but its form did not appear. The page made ${n} request${
                        n === 1 ? '' : 's'
                    } that send data, which a read-only inspection never allows, and a page that loads its form that way cannot be read yet. Your link is saved and nothing was filled in or submitted. Open it in your browser to see the form.`,
                };
            }

            if (enteredByUser(context)) {
                return {
                    label: 'Form not located',
                    tone: 'warn',
                    explanation: `We opened the application link you entered${
                        context.listingHost ? ` (${context.listingHost})` : ''
                    } but could not find an application form on that page. The inspector itself ran normally. If the form is on a different page, enter that page's link instead.`,
                };
            }

            return {
                label: 'Needs application URL',
                tone: 'warn',
                explanation: `We reached ${
                    context.listingHost
                        ? `the listing on ${context.listingHost}`
                        : 'the job listing'
                }, but could not find the employer's application form, so there was nothing to read. The inspector itself ran normally. Open the posting, copy the link you would apply through, and enter it below.`,
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
                label: 'Needs application URL',
                tone: 'warn',
                explanation:
                    'The application form was not reached. Enter the link you would apply through.',
            };
    }
}

/** Outcomes where the person's own link is the natural next step. */
const URL_NEEDED = new Set(['application_not_found', 'needs_application_url']);

/** Everything the wording may know about a result, read from the stored evidence and never guessed. */
export function contextOf(result: InspectionResult | null): OutcomeContext {
    if (result === null) return {};
    const d = result.diagnostics;

    return {
        listingHost: hostOf(d?.requested_url ?? d?.final_url),
        startOrigin: d?.start_origin ?? null,
        blockedRequests: (d?.warnings ?? result.warnings ?? []).filter(
            (w) => w === 'blocked_non_get',
        ).length,
        httpStatus: d?.http_status ?? null,
    };
}

export function presentInspection(
    state: ApplicationInspectionState | null,
): InspectionPresentation {
    const run = state?.workflow_run ?? null;
    const result = state?.latest_result ?? null;
    const active = run?.status === 'pending' || run?.status === 'running';
    const context = contextOf(result);
    const outcome =
        result?.inspection_outcome != null
            ? describeOutcome(
                  result.inspection_outcome,
                  result.stop_reason,
                  context,
              )
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
        // A person who has just entered the link is not asked to enter it again: for them "no form" is a finding about
        // that page, not a missing link.
        needsApplicationUrl:
            !active &&
            !outcomeIsEarlier &&
            result?.inspection_outcome != null &&
            URL_NEEDED.has(result.inspection_outcome) &&
            !(
                result.inspection_outcome === 'application_not_found' &&
                enteredByUser(context)
            ),
        context,
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
                    ? describeOutcome(summary.outcome, summary.stop_reason, {
                          startOrigin: summary.start_origin,
                          blockedRequests: summary.blocked_requests,
                          httpStatus: summary.http_status,
                      })
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
 * The two ways to start the FIRST inspection of a job, both explicit confirmations in the setup drawer. Both ride the existing
 * inspection request: the listing choice sends nothing but `only_if_none`; the link choice adds the person's own
 * `application_url` (validated and saved as theirs by the server). `only_if_none` makes the server start an inspection only
 * if the job still has none, so a second tab or a stale page can never create a second run over a result that already exists.
 */
export function firstInspectionOptions(
    choice: 'listing' | 'url',
    applicationUrl?: string,
    onInvalid?: (message: string) => void,
): StartOptions {
    return choice === 'url'
        ? { onlyIfNone: true, applicationUrl, onInvalid }
        : { onlyIfNone: true };
}
