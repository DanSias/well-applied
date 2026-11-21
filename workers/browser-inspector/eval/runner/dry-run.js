// DRY RUN: the real resolver and the real guarded browser, against LOCAL FIXTURE SERVERS ONLY. Hosts named like real aggregators and ATSs are mapped to
// local servers inside the browser, and the guard allows exactly those host:port pairs and refuses everything else, so nothing can leave the machine.
// The only module in the runner that imports the inspector; it has no way to name a real URL.
import { resolveDestination } from '../../src/resolution/resolve.js';
import { startResolutionSites, FAST } from '../../test/support/resolution-sites.js';

/** @returns {Promise<{resolve: Function, getUrl: Function, close: () => Promise<void>, seen: Array}>} */
export async function startDryRun() {
    const env = await startResolutionSites();
    const toLocal = (logical) => {
        const u = new URL(logical);
        const site = Object.entries(env.sites.origins).find(([, o]) => new URL(o).hostname === u.hostname);
        if (!site) throw new Error(`dry run refuses a host that is not a local fixture: ${u.hostname}`);

        return `${site[1]}${u.pathname}${u.search}`;
    };

    return {
        seen: env.seen,
        getUrl: async (c) => c.fixture.logical_url,
        resolve: async (logical, options) => resolveDestination(toLocal(logical), { ...options, guard: env.sites.guard(env.hostsWithPorts()), launch: env.sites.launch(env.mapped()), timeouts: FAST }),
        close: () => env.close(),
    };
}
