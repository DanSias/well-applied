// The LIVE adapter: the two functions the runner core needs for a live run, and nothing else. It is NOT a crawler. It builds a guard from the inspector's own
// createGuard (adding only a refusing host policy and the real address check), launches the inspector's own guarded Chromium, and calls resolveDestination().
// It never touches the guard's GET/HEAD rule, the sandbox, the isolation or the relay, and is not part of the worker image or any normal inspection.
//
// `testing` exists for the local HTTPS fixture tests ONLY: an address check that vouches for fixture hosts, a launch function that maps them to 127.0.0.1, and a
// guard wrapper whose isAllowedHost vouches for the same names (so the post-connection loopback check does not refuse a fixture; it does NOT bypass the host policy).
// The CLI never passes it (a test asserts that), so a real run always uses the real DNS/private-address check and the real launcher.
import { createGuard } from '../../src/guard.js';
import { launchChromium } from '../../src/browser.js';
import { resolvesToPrivateAddress } from '../../src/url-safety.js';
import { resolveDestination } from '../../src/resolution/resolve.js';
import { createHostPolicy } from './host-policy.js';
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
            const policy = createHostPolicy({ sourceHost: new URL(url).hostname, reviewedHosts: reviewedRedirectHosts, pacer, deadline, addressCheck, robots });
            // A fresh guard per case, so its per-host address cache never carries one case's approvals into the next.
            const real = createGuard({ resolve: (u) => policy.check(u) });
            const guard = testing?.wrapGuard ? testing.wrapGuard(real) : real;
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
                timeouts: testing?.timeouts,
                allowTrackerRedirects: false,
                reviewedRedirectHosts,
                beforeFollow: async (candidate) => {
                    const u = new URL(candidate.url);
                    // The Apply candidate's path is judged against the dated robots snapshot BEFORE it is requested. (The guard caches its per-host decision, so this is the
                    // one place a path can be judged; a later redirect hop on a host already visited is not.)
                    if (robots.disallows(u.hostname, `${u.pathname}${u.search}`)) return 'robots_disallowed_requires_review';

                    return policy.approveDestination(u.hostname);
                },
            });

            return { ...resolution, host_policy: { approved_hosts: policy.approvedHosts(), paced_hosts: policy.pacedHosts(), refusals: policy.refusals.map((r) => `${r.reason}:${r.host}`) } };
        },
    };
}
