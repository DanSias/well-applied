// The caller's per-hop host policy for a LIVE case, plugged into the guard's existing, injectable address check (createGuard({ resolve })). The guard
// already asks that function about EVERY document redirect BEFORE Chromium follows it (guard.js pauses each document redirect at the response stage),
// about every main-frame navigation before it is sent, and about every subresource. So this policy is a PER-HOP PREFLIGHT, and an unapproved host
// is never requested. It only ever REFUSES more: the real address check runs first, and the guard's GET/HEAD-only rule is untouched.
//
// A host is approved only if it is: the case's own source host; a known ATS host; a host the resolver chose as the single Apply destination (after its
// own gates); or a host a person reviewed (from the authorization). Anything else, including third-party subresource hosts, is refused.
//   Consequence (a real cost, documented): third-party scripts, fonts and trackers never load, so a page that only builds its Apply link with
//   JavaScript from a third-party host may show no link. That fails safe: the case is unresolved, never followed blindly.
import { platformOf } from '../../src/resolution/hosts.js';
import { PacingPastDeadline } from '../runner/pacer.js';

export function createHostPolicy({ sourceHost, reviewedHosts = [], pacer, deadline, addressCheck, robots }) {
    const approved = new Set([sourceHost.toLowerCase(), ...reviewedHosts.map((h) => h.toLowerCase())]);
    const pacedAlready = new Set();
    const refusals = [];
    const paced = new Set();

    const refuse = (reason, host) => {
        refusals.push({ reason, host });

        return { safe: false, reason: `host_policy:${reason}:${host}` };
    };

    return {
        refusals,
        pacedHosts: () => [...paced].sort(),
        approvedHosts: () => [...approved].sort(),
        /** The resolver chose this host as the single Apply destination. Pace it once, now, so the request that follows is not paced twice. */
        async approveDestination(host) {
            const h = host.toLowerCase();
            approved.add(h);
            try {
                if ((await pacer.waitFor(h, { deadline })) > 0) paced.add(h);
                pacer.mark(h);
                pacedAlready.add(h);
            } catch (e) {
                if (e instanceof PacingPastDeadline) return 'pacing_would_pass_deadline';
                throw e;
            }

            return null;
        },
        /** The guard's per-URL check: real address safety first, then this policy, then pacing. */
        async check(url) {
            const address = await addressCheck(url);
            if (!address.safe) return address;
            const host = url.hostname.toLowerCase();
            const rule = robots?.disallows(host, `${url.pathname}${url.search}`);
            if (rule) return refuse('robots_disallowed', host);
            if (!approved.has(host) && !platformOf(host)) return refuse('host_not_approved', host);
            if (pacedAlready.delete(host)) return { safe: true };
            try {
                if ((await pacer.waitFor(host, { deadline })) > 0) paced.add(host);
                pacer.mark(host);
            } catch (e) {
                if (e instanceof PacingPastDeadline) return refuse('pacing_would_pass_deadline', host);
                throw e;
            }

            return { safe: true };
        },
    };
}
