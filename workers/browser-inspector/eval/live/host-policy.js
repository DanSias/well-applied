// The caller's PER-REQUEST policy for a LIVE case. It does NOT depend on the guard's per-host cache: the guard remembers its address-safety verdict for a
// host after the first request, so anything decided there is decided once per host. This policy is instead evaluated for EVERY request, by an additional
// Playwright route handler registered after the guard's own (handlers run newest-first), which either refuses the request or hands it on to the guard's
// handler with route.fallback(). It therefore only ever REFUSES more; the guard's own checks (GET/HEAD only, popups, addresses, redirect limit) still run
// on everything it lets through. An HTTP redirect hop is its own request, so each hop is decided before it is sent.
//
// For every request:  host approved?  (approval is a fixed set; a redirect target is NEVER added to it)
// For every NAVIGATION (a document request, including each redirect hop and a host revisited within the same case):  robots path rule, then pacing.
//   Pacing is serialised, so two simultaneous navigations to one host are spaced too.
//
// A host is approved only if it is: the case's own source host; a known ATS host; the single Apply destination the resolver chose (after its own gates);
// or a host a person reviewed (from the authorization). Anything else, including third-party subresource hosts, is refused and never requested.
//   Cost (documented): third-party scripts, fonts and trackers never load, so a page that builds its Apply link from a third-party script may show none.
//   That fails safe: the case is unresolved, never followed blindly.
import { platformOf } from '../../src/resolution/hosts.js';
import { PacingPastDeadline } from '../runner/pacer.js';

export function createHostPolicy({ sourceHost, reviewedHosts = [], pacer, deadline, robots }) {
    const approved = new Set([sourceHost.toLowerCase(), ...reviewedHosts.map((h) => h.toLowerCase())]);
    const refusals = [];
    const paced = new Set();
    /** One entry per decision, so a test can see that EVERY request (not every host) was decided. */
    const decisions = [];
    let queue = Promise.resolve();

    const refuse = (reason, host, navigation) => {
        refusals.push({ reason, host });
        decisions.push({ host, navigation, outcome: `refused:${reason}` });

        return { ok: false, reason: `host_policy:${reason}:${host}` };
    };

    const decideNow = async (url, { navigation }) => {
        const host = url.hostname.toLowerCase();
        if (!approved.has(host) && !platformOf(host)) return refuse('host_not_approved', host, navigation);
        if (navigation) {
            if (robots?.disallows(host, `${url.pathname}${url.search}`)) return refuse('robots_disallowed', host, navigation);
            try {
                if ((await pacer.waitFor(host, { deadline })) > 0) paced.add(host);
                pacer.mark(host);
            } catch (e) {
                if (e instanceof PacingPastDeadline) return refuse('pacing_would_pass_deadline', host, navigation);
                throw e;
            }
        }
        decisions.push({ host, navigation, outcome: 'allowed' });

        return { ok: true };
    };

    return {
        refusals,
        decisions,
        pacedHosts: () => [...paced].sort(),
        approvedHosts: () => [...approved].sort(),
        /**
         * The resolver chose this host as the single Apply destination (after its own gates). This is the ONLY way a host becomes approved while a case runs,
         * and it is never called for a redirect target.
         */
        approveDestination(host) {
            approved.add(host.toLowerCase());
        },
        /** Decide one request. Serialised: decisions (and their pacing waits) never interleave. */
        decide(url, { navigation }) {
            const next = queue.then(() => decideNow(url, { navigation }));
            queue = next.catch(() => {});

            return next;
        },
    };
}

/**
 * Wraps the inspector's own guard so every request also passes the policy. install() registers the policy's route handler AFTER the guard's (so it runs
 * first); a refusal is aborted and recorded as a violation the walk reads through take() like any other. Nothing of the guard is replaced or weakened.
 */
export function withRequestPolicy(guard, policy) {
    const own = [];

    return {
        ...guard,
        async install(context) {
            await guard.install(context);
            await context.route('**/*', async (route) => {
                const request = route.request();
                let url;
                try {
                    url = new URL(request.url());
                } catch {
                    return route.fallback();
                }
                if (url.protocol !== 'http:' && url.protocol !== 'https:') return route.fallback(); // data:, blob:, about: are not requests
                const navigation = request.isNavigationRequest();
                const verdict = await policy.decide(url, { navigation });
                if (verdict.ok) return route.fallback();
                let main = false;
                try {
                    main = navigation && request.frame().parentFrame() === null;
                } catch {
                    /* a frame that went away: treated as not main */
                }
                own.push({ kind: 'prohibited', main, url: `${url.protocol}//${url.host}${url.pathname}`, reason: verdict.reason });

                return route.abort('blockedbyclient');
            });
        },
        /**
         * Playwright route handlers do NOT see HTTP redirect hops (Chromium follows them inside the network stack): a hop is only visible by pausing the redirect
         * RESPONSE, exactly as the guard does for its own address check. So the policy adds a second, independent Fetch interception at the response stage: every redirect
         * is decided (approval, and for a document hop also robots and pacing) BEFORE Chromium follows it, and a refused hop is failed so the target is never requested.
         */
        async attach(page) {
            await guard.attach(page);
            const cdp = await page.context().newCDPSession(page);
            const { frameTree } = await cdp.send('Page.getFrameTree');
            const mainFrameId = frameTree.frame.id;
            cdp.on('Fetch.requestPaused', async (event) => {
                try {
                    const status = event.responseStatusCode ?? 0;
                    const location = (event.responseHeaders ?? []).find((h) => h.name.toLowerCase() === 'location')?.value;
                    if (!(status >= 300 && status < 400 && location)) return await cdp.send('Fetch.continueRequest', { requestId: event.requestId });
                    let target;
                    try {
                        target = new URL(location, event.request.url);
                    } catch {
                        return await cdp.send('Fetch.continueRequest', { requestId: event.requestId }); // the guard's own handler refuses a malformed redirect
                    }
                    if (target.protocol !== 'http:' && target.protocol !== 'https:') return await cdp.send('Fetch.continueRequest', { requestId: event.requestId });
                    const navigation = event.resourceType === 'Document';
                    const verdict = await policy.decide(target, { navigation });
                    if (verdict.ok) return await cdp.send('Fetch.continueRequest', { requestId: event.requestId });
                    own.push({ kind: 'prohibited', main: navigation && event.frameId === mainFrameId, url: `${target.protocol}//${target.host}${target.pathname}`, reason: verdict.reason });

                    return await cdp.send('Fetch.failRequest', { requestId: event.requestId, errorReason: 'BlockedByClient' });
                } catch {
                    // The page or request went away while it was paused: nothing left to decide.
                }
            });
            await cdp.send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Response' }] });
        },
        take() {
            return [...guard.take(), ...own.splice(0)];
        },
    };
}
