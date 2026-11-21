// Orchestrates one inspection: validate the start URL, open a guarded browser, and walk at most THREE pages (the start
// page plus two Apply-link follows) until an application form is reached or there is a clear reason to stop. Always
// returns a structured result — never throws for an ordinary/expected outcome. This is the reusable boundary poll.js
// calls into; src/cli.js is a thin wrapper around it. See README.md "Architecture boundary" and "Navigation".
//
// Read-only: pages are only navigated to (GET, through the guard). Nothing is clicked, typed, submitted, accepted or
// created, and an "Apply" button that is not a link is reported, not pressed.
import { redactText } from './redact.js';
import { createDeadline, DEFAULT_BUDGET_MS, DeadlineExceeded } from './budget.js';
import { createGuard, verifyConnectedAddress } from './guard.js';
import { launchChromium, openGuardedPage, navigate, traceFor, safeRedact } from './browser.js';
import { collectSignals, classifyPage } from './page-kind.js';
import { contextFor } from './page-context.js';
import { collectLinkSignals, rankApplyCandidates } from './apply-links.js';
import * as greenhouse from './extractors/greenhouse.js';
import { discoverForm } from './discovery/index.js';
import { readOnlyPage } from './discovery/page-adapter.js';
import { genericDiscoveryRequested } from './discovery/flag.js';
import { ashbySchemaRequested } from './transport/flag.js';
import { createAshbyTransport } from './transport/ashby.js';
import { fetchAshbySchema } from './transport/ashby-schema.js';
import { parseAshbyJobUrl } from './providers/ashby/target.js';
import {
    succeeded,
    unsupported,
    stopped,
    failed,
    FailureCategory,
    InspectionOutcome,
    StopReason,
} from './result.js';

export const MAX_PAGES = 3;
export const MAX_FOLLOWS = 2;

/**
 * @param {string} rawUrl
 * @param {object} [options]
 * @param {number} [options.budgetMs] total execution budget; Laravel sends it with the claim
 * @param {ReturnType<typeof createGuard>} [options.guard] tests only (fixture hosts); never built from a claim
 * @param {() => Promise<import('playwright').Browser>} [options.launch]
 * @param {{navigationMs?: number, networkIdleMs?: number, settleMs?: number}} [options.timeouts] per-step overrides, tests only
 * @param {string|null} [options.startOrigin] where Well Applied says the start URL came from ('user', 'resolved', 'provider', 'source'): context for
 *   recognising an application form (generic discovery only)
 * @param {boolean} [options.genericDiscovery] read forms with no dedicated extractor generically (default: BROWSER_WORKER_GENERIC_DISCOVERY=1)
 * @param {boolean} [options.ashbySchema] read a verified jobs.ashbyhq.com job link from Ashby's public form schema before any browser
 *   is launched (default: BROWSER_WORKER_ASHBY_SCHEMA=1). Anything else, and every other provider, is unchanged.
 * @param {(deadline: object) => {send: Function}} [options.ashbyTransport] tests only: a transport factory. Production uses the real, fixed-destination one.
 * @param {Function} [options.onForm] Destination Resolution only (src/resolution/): called INSTEAD of form extraction when the walk reaches
 *   a page classified as an application form. Default: extract, exactly as before.
 * @param {(event: object) => void} [options.observe] Destination Resolution only: told what the walk saw (navigations, classifications, Apply
 *   candidates). Read-only; default: nothing.
 * @param {(candidate: object, context: object) => Promise<object|null>} [options.onCandidate] Destination Resolution only: may stop the walk
 *   BEFORE the single Apply destination is requested (a policy gate). Default: always follow, exactly as before.
 * @returns {Promise<object>} one of the result.js shapes
 */
export async function inspect(rawUrl, options = {}) {
    const {
        budgetMs = DEFAULT_BUDGET_MS,
        guard = createGuard(),
        launch = launchChromium,
        timeouts = {},
        genericDiscovery = genericDiscoveryRequested(),
        startOrigin = null,
        ashbySchema = ashbySchemaRequested(),
        ashbyTransport = (d) => createAshbyTransport({ deadline: d }),
        onForm = null,
        observe = null,
        onCandidate = null,
    } = options;

    const startedAt = Date.now();
    const deadline = createDeadline(budgetMs);
    const state = {
        startedAt,
        requestedUrl: safeRedact(rawUrl),
        trace: [],
        warnings: [],
        pages: 0,
        follows: 0,
        pageTitle: null,
        httpStatus: null,
        finalUrl: null,
        candidates: [],
        navigationMs: 0,
        step: 'validating the start URL',
        budgetMs,
    };

    const first = await guard.checkUrl(rawUrl);
    if (!first.ok) {
        return refusal(state, first.reason);
    }

    // Ashby, flag on, a verified jobs.ashbyhq.com job link: read the form from the provider's own schema first. The same deadline
    // governs it, and one attempt per operation is all it gets: an ordinary failure falls through to the existing read-only
    // browser path (with a warning saying why); a security refusal stops here, with its evidence.
    if (ashbySchema && parseAshbyJobUrl(first.url.toString()).ok) {
        let outcome;
        try {
            outcome = await fetchAshbySchema(first.url.toString(), ashbyTransport(deadline));
        } catch (error) {
            // Not a transport refusal or an ordinary failure: a bug. Never papered over by a fallback.
            return failed({
                failureCategory: FailureCategory.UnexpectedError,
                failureMessage: redactText(`Ashby schema retrieval failed unexpectedly: ${String(error?.message ?? error)}`),
                warnings: [...state.warnings, 'ashby_schema_unexpected_error'],
                diagnostics: diagnostics(state),
            });
        }
        const done = ashbyResult(outcome, state);
        if (done) return done;
    }

    let browser;
    let expired = false;
    // The hard stop: closing the browser makes every pending page call throw, so a hung page cannot outlast the budget.
    const timer = setTimeout(() => {
        expired = true;
        browser?.close().catch(() => {});
    }, deadline.untilStop());

    try {
        browser = await launch();
        const { page } = await openGuardedPage(browser, guard);

        return await walk(page, first.url.toString(), { state, guard, deadline, rawUrl, timeouts, genericDiscovery, startOrigin, onForm, observe, onCandidate });
    } catch (error) {
        if (expired || error instanceof DeadlineExceeded || deadline.expired()) {
            return failed({
                failureCategory: FailureCategory.NavigationTimeout,
                failureMessage: `The inspection used its whole ${Math.round(budgetMs / 1000)}s time budget while ${state.step}.`,
                diagnostics: diagnostics(state, { deadline_exceeded: true }),
            });
        }

        return failed({
            failureCategory:
                error?.name === 'TimeoutError' ? FailureCategory.NavigationTimeout : FailureCategory.UnexpectedError,
            failureMessage: redactText(String(error?.message ?? error)),
            diagnostics: diagnostics(state),
        });
    } finally {
        clearTimeout(timer);
        await browser?.close().catch(() => {});
    }
}

/** A finished result from an Ashby schema attempt, or null to continue with the browser (an ordinary failure, already noted in the warnings). */
export function ashbyResult(outcome, state) {
    if (outcome.ok) {
        const { contract, coverage } = outcome;

        return succeeded({
            ats: 'ashby',
            requestedUrl: state.requestedUrl,
            finalUrl: null,
            resolvedUrl: null,
            fields: contract.fields,
            warnings: [...state.warnings, ...contract.warnings],
            inspectionOutcome: InspectionOutcome.Partial,
            stopReason: null,
            diagnostics: diagnostics(state, {
                http_status: null,
                extraction_ms: outcome.ms,
                field_count: contract.fields.length,
                provider_schema: { application_form_fields: coverage.application_form_fields, survey_fields: coverage.survey_fields },
            }),
        });
    }

    const evidence = `${outcome.stage}/${outcome.reason}`;
    if (outcome.kind === 'availability') {
        state.warnings.push(`ashby_schema_unavailable:${evidence}`);

        return null;
    }

    return failed({
        failureCategory: FailureCategory.UnexpectedError,
        failureMessage: redactText(`Ashby schema retrieval was refused by security validation (${evidence}). No browser fallback was attempted.`),
        warnings: [...state.warnings, 'ashby_schema_security_refusal', `ashby_schema_refused:${evidence}`],
        diagnostics: diagnostics(state, { ashby_schema: { kind: 'security', stage: outcome.stage, reason: outcome.reason } }),
    });
}

async function walk(page, startUrl, { state, guard, deadline, rawUrl, timeouts, genericDiscovery, startOrigin, onForm, observe, onCandidate }) {
    let url = startUrl;

    for (;;) {
        if (deadline.expired()) throw new DeadlineExceeded('budget used');

        const hop = state.pages; // 0 for the start page
        state.pages += 1;
        state.step = hop === 0 ? 'loading the application URL' : `loading Apply destination ${hop} of ${MAX_FOLLOWS}`;

        const nav = await navigate(page, url, { deadline, guard, timeouts });
        state.warnings.push(...nav.warnings);
        state.navigationMs += nav.navigationMs;
        state.finalUrl = safeRedact(nav.finalUrl);
        state.httpStatus = nav.status;

        const prohibited = nav.violations.find((v) => v.main);
        const subresource = nav.violations.filter((v) => !v.main);
        for (const v of subresource) state.warnings.push(`blocked_${v.kind}`);

        const pageTrace = await traceFor(nav.response, hop === 0 ? 'start' : 'link');
        state.trace.push(...pageTrace);
        observe?.({ event: 'navigated', hop, requested: safeRedact(url), final: safeRedact(nav.finalUrl), status: nav.status, trace: pageTrace, refused: prohibited?.reason ?? null, error: nav.error ? String(nav.error?.message ?? nav.error).match(/net::ERR_\w+/)?.[0] ?? nav.error?.name ?? 'error' : null });
        if (nav.response === null) state.trace.push({ kind: hop === 0 ? 'start' : 'link', url: safeRedact(url), status: null });

        if (prohibited) {
            return halt(state, rawUrl, prohibited.kind === 'redirect_limit' ? StopReason.TooManyRedirects : StopReason.DestinationProhibited, {
                refused: prohibited.reason,
            });
        }

        if (nav.error) {
            const message = String(nav.error?.message ?? nav.error);
            if (nav.error?.name === 'TimeoutError') {
                return failed({
                    failureCategory: FailureCategory.NavigationTimeout,
                    failureMessage: redactText(`Timed out ${state.step} (page ${state.pages} of up to ${MAX_PAGES}).`),
                    diagnostics: diagnostics(state),
                });
            }
            if (/Download is starting/i.test(message)) {
                // An Apply link (or start URL) that is a file, not a page: not an application destination.
                state.warnings.push('destination_is_download');

                return stopped({
                    inspectionOutcome: InspectionOutcome.ApplicationNotFound,
                    stopReason: StopReason.NoApplyLink,
                    ...envelope(state, rawUrl),
                });
            }
            if (/ERR_TOO_MANY_REDIRECTS/.test(message)) return halt(state, rawUrl, StopReason.TooManyRedirects);
            if (/ERR_/.test(message)) return halt(state, rawUrl, StopReason.DestinationUnreachable, { error: message.match(/net::ERR_\w+/)?.[0] ?? null });

            throw nav.error;
        }

        const connected = await verifyConnectedAddress(nav.response, guard);
        if (connected) {
            return halt(state, rawUrl, StopReason.DestinationProhibited, { refused: connected.reason });
        }

        state.step = 'reading the page';
        const signals = await page.evaluate(collectSignals);
        state.pageTitle = signals.title;
        // Context for recognising an application form (generic discovery only; otherwise the classifier is exactly what it was):
        // how this page was reached. The first page is where the claim says its URL came from; any later page was reached by
        // following an identified Apply link.
        const kind = classifyPage(signals, { status: nav.status, context: contextFor({ hop, startOrigin }), extended: genericDiscovery });
        observe?.({ event: 'classified', hop, kind: kind.kind, ats: kind.ats ?? null, reasons: kind.reasons, title: signals.title, warnings: [...state.warnings] });

        switch (kind.kind) {
            case 'blocked':
                return stopped({
                    inspectionOutcome: InspectionOutcome.Blocked,
                    stopReason: kind.stopReason === StopReason.Captcha ? StopReason.Captcha : StopReason.AntiBot,
                    ...envelope(state, rawUrl, { classified_as: kind.reasons }),
                });
            case 'login':
                return stopped({
                    inspectionOutcome: InspectionOutcome.AuthenticationRequired,
                    stopReason: StopReason.LoginWall,
                    ...envelope(state, rawUrl, { classified_as: kind.reasons }),
                });
            case 'unreachable':
                return halt(state, rawUrl, StopReason.DestinationUnreachable, { classified_as: kind.reasons });
            case 'form':
                return onForm ? onForm(page, kind, { hop, state }) : reachedForm(page, kind, { state, rawUrl, genericDiscovery });
        }

        // ---- a listing: look for ONE Apply destination, never click, never pick between several ----
        state.step = 'looking for an Apply link';
        const { candidates, warnings } = rankApplyCandidates(await page.evaluate(collectLinkSignals), nav.finalUrl);
        state.warnings.push(...warnings);
        state.candidates = candidates.slice(0, 5).map((c) => ({ url: safeRedact(c.url), text: c.text, kind: c.kind }));
        observe?.({ event: 'candidates', hop, count: candidates.length, candidates: state.candidates, warnings });

        if (candidates.length === 0) {
            return stopped({
                inspectionOutcome: InspectionOutcome.ApplicationNotFound,
                stopReason: StopReason.NoApplyLink,
                ...envelope(state, rawUrl, { classified_as: kind.reasons }),
            });
        }

        if (candidates.length > 1) {
            return stopped({
                inspectionOutcome: InspectionOutcome.NeedsApplicationUrl,
                stopReason: StopReason.MultipleApplyLinks,
                ...envelope(state, rawUrl),
            });
        }

        if (state.follows >= MAX_FOLLOWS || state.pages >= MAX_PAGES) {
            return halt(state, rawUrl, StopReason.TooManySteps);
        }

        // A policy gate (Destination Resolution only) sees the single destination before anything is requested from it.
        if (onCandidate) {
            const stopped = await onCandidate(candidates[0], { hop });
            if (stopped) return stopped;
        }

        // The destination is checked BEFORE any request is made to it.
        const verdict = await guard.checkUrl(candidates[0].url);
        if (!verdict.ok) {
            return halt(state, rawUrl, StopReason.DestinationProhibited, { refused: verdict.reason });
        }

        state.follows += 1;
        url = verdict.url.toString();
    }
}

async function reachedForm(page, kind, { state, rawUrl, genericDiscovery }) {
    const resolvedUrl = page.url();

    if (kind.ats === 'greenhouse' && (await greenhouse.detect(page))) {
        state.step = 'extracting the form';
        const extractionStartedAt = Date.now();
        const { fields, warnings, diagnostics: extra } = await greenhouse.extract(page);

        return succeeded({
            ats: 'greenhouse',
            requestedUrl: state.requestedUrl,
            finalUrl: state.finalUrl,
            resolvedUrl,
            fields,
            warnings: [...state.warnings, ...warnings],
            inspectionOutcome: fields.length === 0 ? InspectionOutcome.Partial : InspectionOutcome.Complete,
            stopReason: fields.length === 0 ? StopReason.NoFieldsExtracted : null,
            diagnostics: diagnostics(state, { ...extra, extraction_ms: Date.now() - extractionStartedAt, classified_as: kind.reasons }),
        });
    }

    // No dedicated extractor. With generic discovery on, read it from what the page itself says (read-only: nothing is clicked,
    // typed or selected). Greenhouse never reaches here: it was handled above, unchanged.
    if (genericDiscovery) {
        state.step = 'reading the form generically';
        const extractionStartedAt = Date.now();
        const found = await discoverForm(readOnlyPage(page));

        return succeeded({
            ats: kind.ats === 'lever' ? 'lever' : null,
            requestedUrl: state.requestedUrl,
            finalUrl: state.finalUrl,
            resolvedUrl,
            fields: found.fields,
            // How this page came to be read as a form is evidence too: a page that is not self-evidently an application says so.
            warnings: [...state.warnings, ...found.warnings, 'generic_discovery', ...(kind.inferred ? [kind.inferred === 'content' ? 'application_form_inferred_from_content' : 'application_form_inferred_from_context'] : [])],
            inspectionOutcome: found.inspection_outcome,
            stopReason: found.stop_reason,
            diagnostics: diagnostics(state, { ...found.diagnostics, extraction_ms: Date.now() - extractionStartedAt, classified_as: kind.reasons }),
        });
    }

    // A form is here, but this inspector cannot read it. Say so: this is not "no application found".
    return unsupported({
        ats: kind.ats === 'lever' ? 'lever' : null,
        stopReason: kind.ats === 'lever' ? StopReason.AtsNotExtractable : StopReason.FormWithoutAdapter,
        requestedUrl: state.requestedUrl,
        finalUrl: state.finalUrl,
        resolvedUrl,
        warnings: state.warnings,
        diagnostics: diagnostics(state, { classified_as: kind.reasons }),
    });
}

/** A start URL refused before any browser opened. */
function refusal(state, reason) {
    const unreachable = reason === 'dns_resolution_failed';
    state.trace.push({ kind: 'start', url: state.requestedUrl, status: null });

    return stopped({
        inspectionOutcome: InspectionOutcome.NeedsApplicationUrl,
        stopReason: unreachable ? StopReason.DestinationUnreachable : StopReason.DestinationProhibited,
        ...envelope(state, null, { refused: reason }),
    });
}

function halt(state, rawUrl, stopReason, extra = {}) {
    return stopped({
        inspectionOutcome: InspectionOutcome.NeedsApplicationUrl,
        stopReason,
        ...envelope(state, rawUrl, extra),
    });
}

function envelope(state, rawUrl, extra = {}) {
    return {
        requestedUrl: state.requestedUrl,
        finalUrl: state.finalUrl,
        warnings: state.warnings,
        diagnostics: diagnostics(state, extra),
    };
}

/**
 * Small, structured, non-content diagnostics only — never a raw DOM/accessibility-tree/page-text dump, never cookies or
 * storage, and never a URL with a query string. See docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun".
 */
function diagnostics(state, extra = {}) {
    return {
        total_ms: Date.now() - state.startedAt,
        navigation_ms: state.navigationMs,
        extraction_ms: null,
        page_title: state.pageTitle,
        http_status: state.httpStatus,
        pages_visited: state.pages,
        follows: state.follows,
        navigation: state.trace.slice(0, 8),
        candidates: state.candidates,
        timestamp: new Date().toISOString(),
        ...extra,
    };
}

