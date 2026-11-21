// Destination Resolution: from a discovered job's source URL to a PROPOSED employer application destination, or a precise reason
// none was found. READ-ONLY, and not a second crawler: it drives the inspector's own guarded walk (src/inspect.js: the guard,
// navigation hop limits, the sign-in / CAPTCHA / unreachable classifier, the Apply-link ranker) and only replaces what happens at the end:
// a page classified as an application form is RECORDED, not extracted.
//
// It never clicks, types, authenticates, uploads, submits or creates anything; the browser is GET/HEAD-only (guard.js). It knows nothing
// about Opportunities, Applications, agent runs or the database, so it can run on a Job before it is anything else.
//
// Statuses never overstate:
//   verified_direct         the form was OBSERVED on the very page (and board) the source URL names, on a non-aggregator site
//   proposed_by_resolution  reached by following one identified Apply link; a PROPOSAL, never verified (a person must confirm)
//   login_required | unreachable | blocked | unresolved   with a precise reason
import { inspect, MAX_FOLLOWS, MAX_PAGES } from '../inspect.js';
import { MAX_REDIRECTS, createGuard } from '../guard.js';
import { safeRedact } from '../browser.js';
import { InspectionOutcome, StopReason } from '../result.js';
import { hostType, platformOf, redirectorSignalsFor, trackerRuleFor } from './hosts.js';

export const RESOLUTION_SCHEMA = 'destination-resolution/1';
export const RESOLVER_VERSION = '0.1.0';
export const ResolutionStatus = Object.freeze({
    VerifiedDirect: 'verified_direct',
    Proposed: 'proposed_by_resolution',
    LoginRequired: 'login_required',
    Unresolved: 'unresolved',
    Unreachable: 'unreachable',
    Blocked: 'blocked',
});

const hostOf = (url) => {
    try {
        return new URL(url).hostname.toLowerCase();
    } catch {
        return null;
    }
};
const typed = (url) => {
    const host = hostOf(url);

    return { url: url ?? null, host, host_type: hostType(host), platform: platformOf(host) };
};

/**
 * @param {string} sourceUrl a discovered job's source URL (its listing, or a direct ATS URL)
 * @param {object} [options]
 * @param {boolean} [options.allowTrackerRedirects] follow a KNOWN click-tracking redirect (default false: it needs its own approval)
 * @param {(candidate: object) => Promise<string|void>} [options.beforeFollow] live runs only: called with the single Apply candidate AFTER the resolver's own gates and BEFORE
 *   it is requested, so a caller can approve its host and pace the request. Default: nothing.
 * @param {string[]} [options.reviewedRedirectHosts] hosts a PERSON has reviewed and cleared as redirectors (default none). An unknown redirector or
 *   click-through is otherwise refused BEFORE it is requested, and flagged for human review.
 * @param {number} [options.budgetMs] @param {object} [options.guard] @param {Function} [options.launch] @param {object} [options.timeouts] as for inspect()
 */
export async function resolveDestination(sourceUrl, options = {}) {
    const { allowTrackerRedirects = false, reviewedRedirectHosts = [], beforeFollow = null, guard = createGuard(), ...passthrough } = options;
    const cleared = new Set(reviewedRedirectHosts.map((h) => String(h).toLowerCase()));
    const source = typed(safeRedact(sourceUrl) ?? String(sourceUrl));
    const hops = [];
    const evidence = { candidates: [], warnings: [] };
    const base = () => ({
        schema: RESOLUTION_SCHEMA,
        resolver_version: RESOLVER_VERSION,
        source,
        provenance: { method: 'guarded_read_only_walk', walk: 'src/inspect.js', limits: { max_pages: MAX_PAGES, max_follows: MAX_FOLLOWS, max_redirects: MAX_REDIRECTS }, tracker_redirects_allowed: allowTrackerRedirects, reviewed_redirect_hosts: [...cleared].sort(), clicks: 0, form_fields_read: 0 },
    });
    const finish = (status, reason, extra = {}) => ({
        ...base(),
        status,
        reason,
        destination: null,
        hops,
        apply_candidates: evidence.candidates,
        needs_human_review: false,
        review_reason: null,
        warnings: evidence.warnings,
        ...extra,
    });
    const refusedTracker = (url, where) => {
        const rule = trackerRuleFor(url);

        return finish(ResolutionStatus.Unresolved, 'tracker_redirect_requires_permission', { tracker: { rule: rule.id, where, note: rule.note }, needs_human_review: false });
    };

    const unknownRedirector = (url, pageUrl, where) => {
        const signals = cleared.has(hostOf(url) ?? '') ? [] : redirectorSignalsFor(url, pageUrl);
        if (signals.length === 0) return null;

        return finish(ResolutionStatus.Unresolved, 'unknown_redirector_requires_review', { redirector: { where, host: hostOf(url), signals }, needs_human_review: true, review_reason: `an unknown redirector or click-through (${signals.join(', ')}) was not followed: a person reviews it first` });
    };

    // Policy gates on the SOURCE itself: nothing is requested.
    if (!allowTrackerRedirects && trackerRuleFor(sourceUrl)) return refusedTracker(sourceUrl, 'source_url');
    const sourceRedirector = unknownRedirector(sourceUrl, null, 'source_url');
    if (sourceRedirector) return sourceRedirector;

    let lastClassified = null;
    const observe = (e) => {
        if (e.event === 'navigated') {
            hops.push({ index: e.hop, requested: typed(e.requested), final: typed(e.final), http_status: e.status, redirect_chain: e.trace.map((t) => ({ kind: t.kind, ...typed(t.url), http_status: t.status })), refused: e.refused, error: e.error, page_kind: null, page_reasons: [] });
        } else if (e.event === 'classified') {
            const hop = hops.find((h) => h.index === e.hop);
            if (hop) Object.assign(hop, { page_kind: e.kind, page_reasons: e.reasons });
            lastClassified = e;
            evidence.warnings.push(...e.warnings.filter((w) => !evidence.warnings.includes(w)));
        } else if (e.event === 'candidates') {
            evidence.candidates = e.candidates.map((c) => ({ ...c, ...typed(c.url) }));
            evidence.warnings.push(...e.warnings);
            const hop = hops.find((h) => h.index === e.hop);
            if (hop) hop.apply_candidates = evidence.candidates;
        }
    };
    // A policy gate on the Apply destination, before anything is requested from it.
    const onCandidate = async (candidate) => {
        if (!allowTrackerRedirects && trackerRuleFor(candidate.url)) return { __resolution: refusedTracker(candidate.url, 'apply_link') };
        const flagged = unknownRedirector(candidate.url, hops.at(-1)?.final.url ?? sourceUrl, 'apply_link');

        if (flagged) return { __resolution: flagged };
        // A caller may veto the follow with a reason (a live run's pacing would pass its deadline): nothing is requested.
        const veto = await beforeFollow?.(candidate);

        return veto ? { __resolution: finish(ResolutionStatus.Unresolved, String(veto), /_requires_review$/.test(String(veto)) ? { needs_human_review: true, review_reason: 'refused by the caller\'s policy before it was requested' } : { needs_human_review: false }) } : null;
    };
    // A page classified as an application form is RECORDED, not read.
    const onForm = async (page, kind, { hop }) => ({ __form: { hop, url: safeRedact(page.url()), reasons: kind.reasons, ats: kind.ats ?? null } });

    const raw = await inspect(sourceUrl, { ...passthrough, guard, genericDiscovery: true, ashbySchema: false, onForm, observe, onCandidate });
    if (raw.__resolution) return raw.__resolution;
    // Whatever the walk itself noted (blocked subresources and methods, load timeouts, ...) is part of the evidence.
    evidence.warnings.push(...(raw.warnings ?? []).filter((w) => !evidence.warnings.includes(w)));

    return flagIntermediateRedirects(interpret(raw, { finish, hops, evidence, source, lastClassified }), cleared);
}

/**
 * An HTTP redirect chain cannot be vetted hop by hop before it is followed (the guard checks each hop's SAFETY, not its purpose), so a redirect that
 * passes through a host that is neither where the page was requested nor where it ended, and is not a known ATS or a reviewed host, is recorded and
 * flagged for a person AFTER the fact. It never upgrades or downgrades the status.
 */
function flagIntermediateRedirects(result, cleared) {
    const via = [];
    for (const h of result.hops ?? []) {
        for (const c of h.redirect_chain ?? []) {
            if (c.kind === 'redirect' && c.host && c.host !== h.requested.host && c.host !== h.final.host && c.host_type !== 'known_ats' && !cleared.has(c.host)) via.push(c.host);
        }
    }
    if (via.length === 0) return result;
    const hosts = [...new Set(via)].sort();

    return { ...result, needs_human_review: true, review_reason: result.review_reason ?? `the redirect chain passed through ${hosts.join(', ')}: an unrecognised intermediate host`, warnings: [...result.warnings, 'unknown_intermediate_redirect_host'], intermediate_redirect_hosts: hosts };
}

/** Maps what the walk ended with onto a resolution. Pure over its inputs. */
function interpret(raw, { finish, hops, evidence, source }) {
    if (raw.__form) return foundForm(raw.__form, { finish, hops, source });

    const lastHop = hops.at(-1) ?? null;
    const diag = raw.diagnostics ?? {};
    const warnings = raw.warnings ?? [];
    const withLast = (extra = {}) => ({ ...extra, ...(lastHop ? { last_page: { index: lastHop.index, ...lastHop.final, page_kind: lastHop.page_kind } } : {}) });

    if (raw.status === 'failed') {
        const timeout = raw.failure_category === 'navigation_timeout';

        return finish(timeout ? ResolutionStatus.Unreachable : ResolutionStatus.Unresolved, timeout ? 'navigation_timeout' : 'resolver_error', withLast({ failure_category: raw.failure_category }));
    }

    switch (raw.stop_reason) {
        case StopReason.LoginWall:
            return finish(ResolutionStatus.LoginRequired, lastHop?.index > 0 ? 'login_wall_at_destination' : 'login_wall_at_source', withLast({ needs_human_review: false }));
        case StopReason.Captcha:
        case StopReason.AntiBot:
            return finish(ResolutionStatus.Blocked, raw.stop_reason === StopReason.Captcha ? 'captcha' : 'anti_bot', withLast({ blocked_by: 'site' }));
        case StopReason.DestinationProhibited: {
            // A refusal by the CALLER's host policy (live runs: an unapproved redirect or destination host, a robots.txt-disallowed path, pacing past the
            // deadline) is not a security refusal: nothing unsafe was attempted. It is unresolved, for a person to review, and the host was never requested.
            const refused = String(diag.refused ?? '');
            if (refused.startsWith('host_policy:')) {
                const [, kind, host = null] = refused.split(':');
                if (kind === 'pacing_would_pass_deadline') return finish(ResolutionStatus.Unresolved, 'pacing_would_pass_deadline', withLast({ needs_human_review: false }));
                const reason = kind === 'robots_disallowed' ? 'robots_disallowed_requires_review' : 'redirect_host_requires_review';

                return finish(ResolutionStatus.Unresolved, reason, withLast({ redirector: { where: 'redirect_or_navigation', host, signals: [kind] }, needs_human_review: true, review_reason: `${kind === 'robots_disallowed' ? 'a path the host\'s robots.txt disallows' : 'an unapproved host'} (${host ?? 'unknown'}) was refused before it was requested` }));
            }

            return finish(ResolutionStatus.Blocked, 'destination_prohibited', withLast({ blocked_by: 'policy', refused: diag.refused ?? null }));
        }
        case StopReason.TooManyRedirects:
            return finish(ResolutionStatus.Blocked, 'redirect_limit', withLast({ blocked_by: 'policy' }));
        case StopReason.DestinationUnreachable:
            return finish(ResolutionStatus.Unreachable, lastHop?.http_status ? `http_${lastHop.http_status}` : (lastHop?.error ?? diag.error ?? raw.diagnostics?.refused ?? 'unreachable'), withLast());
        case StopReason.MultipleApplyLinks:
            return finish(ResolutionStatus.Unresolved, 'multiple_apply_links', withLast({ needs_human_review: true, review_reason: `${evidence.candidates.length} distinct Apply destinations: a person chooses` }));
        case StopReason.TooManySteps:
            return finish(ResolutionStatus.Unresolved, 'too_many_steps', withLast({ needs_human_review: true, review_reason: 'the walk reached its page limit before a form' }));
        case StopReason.NoApplyLink: {
            const reason = warnings.includes('apply_is_button') ? 'apply_is_button' : warnings.includes('apply_is_email') ? 'apply_is_email' : warnings.includes('destination_is_download') ? 'destination_is_download' : 'no_apply_link';
            const landed = lastHop && lastHop.index > 0 ? lastHop : null;
            // Followed one Apply link and stopped on a page that is not a recognised form: a destination worth proposing only when it is on an ATS
            // or a non-aggregator site, and never without human review (it was not observed to be a form).
            if (landed && landed.final.host_type !== 'aggregator' && ['listing', 'form'].includes(landed.page_kind ?? 'listing')) {
                return finish(ResolutionStatus.Proposed, 'destination_reached_form_not_recognised', {
                    destination: { ...landed.final, form_observed: false, basis: 'host_only' },
                    needs_human_review: true,
                    review_reason: landed.final.host_type === 'known_ats' ? 'an ATS page was reached but not recognised as an application form' : 'a non-aggregator page was reached; it was not observed to be an application form and its owner is not confirmed',
                });
            }

            return finish(ResolutionStatus.Unresolved, reason, withLast());
        }
        default:
            return finish(ResolutionStatus.Unresolved, raw.stop_reason ?? 'unclassified_stop', withLast({ needs_human_review: true, review_reason: 'the walk ended in a state the resolver does not classify' }));
    }
}

function foundForm(found, { finish, hops, source }) {
    const lastHop = hops.at(-1);
    const dest = { ...typed(found.url), form_observed: true, form_signals: found.reasons, basis: 'application_form_observed', ats_signal: found.ats };
    if (dest.host_type === 'aggregator') {
        // A form hosted by the aggregator itself is not an employer's application.
        return finish(ResolutionStatus.Unresolved, 'application_hosted_by_aggregator', { destination: { ...dest, basis: 'aggregator_hosted_form' }, needs_human_review: true, review_reason: 'the application form is on the aggregator, not the employer' });
    }
    const followed = hops.length > 1;
    const sameBoard = hops.every((h) => h.final.host === source.host);
    if (!followed && source.host_type !== 'aggregator') {
        return finish(ResolutionStatus.VerifiedDirect, 'form_observed_at_source_url', { destination: dest, needs_human_review: false });
    }
    if (source.host_type !== 'aggregator' && sameBoard) {
        return finish(ResolutionStatus.VerifiedDirect, 'form_observed_on_the_same_board_as_the_source_url', { destination: dest, needs_human_review: false });
    }

    return finish(ResolutionStatus.Proposed, 'form_observed_after_following_apply_link', {
        destination: dest,
        needs_human_review: dest.host_type !== 'known_ats',
        review_reason: dest.host_type !== 'known_ats' ? 'the destination is not on a recognised ATS and its owner is not confirmed' : null,
        ...(lastHop ? { last_page: { index: lastHop.index } } : {}),
    });
}
