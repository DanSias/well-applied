// The LIVE adapter: the two functions the runner core needs for a live run, and nothing else. It is NOT a crawler. It builds a guard from the inspector's own
// createGuard (adding only a refusing host policy and the real address check), launches the inspector's own guarded Chromium, and calls resolveDestination().
// It never touches the guard's GET/HEAD rule, the sandbox, the isolation or the relay, and is not part of the worker image or any normal inspection.
//
// `testing` exists for the local HTTPS fixture tests ONLY: an address check that vouches for fixture hosts, a launch function that maps them to 127.0.0.1, and a
// guard wrapper whose isAllowedHost vouches for the same names (so the post-connection loopback check does not refuse a fixture; it does NOT bypass the host policy).
// The CLI never passes it (a test asserts that), so a real run always uses the real DNS/private-address check and the real launcher.
import { createGuard } from '../../src/guard.js';
import { launchChromium } from '../../src/browser.js';
import { NAVIGATION_TIMEOUT_MS } from '../../src/budget.js';
import { resolvesToPrivateAddress } from '../../src/url-safety.js';
import { resolveDestination } from '../../src/resolution/resolve.js';
import { createHostPolicy, withRequestPolicy } from './host-policy.js';
import { createRobots } from './robots.js';

/**
 * @param {object} p
 * @param {{getSourceUrl: (id: number) => string}} p.reader  read-only source of job source URLs, by id
 * @param {object} p.robotsSnapshot
 * @param {AbortSignal} [p.signal] aborting closes the browser at once, so an interrupted run stops making requests
 * @param {{addressCheck?: Function, launch?: Function, timeouts?: object, wrapGuard?: Function}} [p.testing] tests only
 */
export function createLiveAdapter({ reader, robotsSnapshot, signal, testing }) {
    const robots = createRobots(robotsSnapshot);
    const addressCheck = testing?.addressCheck ?? resolvesToPrivateAddress;
    const baseLaunch = testing?.launch ?? launchChromium;

    return {
        async getUrl(c) {
            return reader.getSourceUrl(c.job_posting_id);
        },
        async resolve(url, options) {
            const { pacer, deadline, reviewedRedirectHosts = [], budgetMs } = options;
            const first = new URL(url);
            // The SOURCE URL is judged against the robots snapshot before any browser exists.
            if (robots.disallows(first.hostname, `${first.pathname}${first.search}`)) {
                return { schema: 'destination-resolution/1', resolver_version: 'live', source: { host: first.hostname }, status: 'unresolved', reason: 'robots_disallowed_requires_review', destination: null, hops: [], apply_candidates: [], needs_human_review: true, review_reason: 'the source URL is a path the host\'s robots.txt disallows: not requested', warnings: [], provenance: { method: 'refused_before_navigation', clicks: 0, form_fields_read: 0 } };
            }
            const policy = createHostPolicy({ sourceHost: new URL(url).hostname, reviewedHosts: reviewedRedirectHosts, pacer, deadline, robots });
            // A fresh guard per case. The guard keeps doing exactly what it does (its address check, cached per host, is only a SAFETY check); the per-request
            // policy is a separate layer on top of it (see host-policy.js), so no decision about approval, robots or pacing relies on that cache.
            const real = createGuard({ resolve: addressCheck });
            const layered = withRequestPolicy(real, policy);
            const guard = testing?.wrapGuard ? testing.wrapGuard(layered) : layered;
            const launch = async () => {
                const browser = await baseLaunch();
                const stop = () => browser.close().catch(() => {});
                if (signal?.aborted) await stop();
                else signal?.addEventListener('abort', stop, { once: true });

                return browser;
            };
            const resolution = await resolveDestination(url, {
                guard,
                launch,
                budgetMs,
                // A navigation may wait out a pacing gap inside the policy before it is sent; its timeout allows for that (tests pass their own).
                timeouts: testing?.timeouts ?? { navigationMs: NAVIGATION_TIMEOUT_MS + pacer.gapMs + 5_000 },
                allowTrackerRedirects: false,
                reviewedRedirectHosts,
                beforeFollow: async (candidate) => {
                    const u = new URL(candidate.url);
                    // The Apply candidate's path is judged against the dated robots snapshot BEFORE it is requested. (The guard caches its per-host decision, so this is the
                    // one place a path can be judged; a later redirect hop on a host already visited is not.)
                    if (robots.disallows(u.hostname, `${u.pathname}${u.search}`)) return 'robots_disallowed_requires_review';
                    policy.approveDestination(u.hostname);
                },
            });

            return { ...resolution, host_policy: { approved_hosts: policy.approvedHosts(), paced_hosts: policy.pacedHosts(), navigations_decided: policy.decisions.filter((d) => d.navigation).length, refusals: policy.refusals.map((r) => `${r.reason}:${r.host}`) } };
        },
    };
}
