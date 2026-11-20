// The results the worker would POST for generic discovery, produced by the real inspect() pipeline over local fixtures and
// normalised (no ports, no timings) so they can be committed. tests/Feature/ApplicationInspection/GenericDiscoveryContractTest.php
// feeds them to Laravel's own request rules and persistence, so the two sides are tested against each other, not against copies.
import { inspect } from '../../src/inspect.js';
import { startSites, fixture } from './fixture-server.js';

export const SNAPSHOTS = [
    { name: 'generic-unknown-ats-form', route: '/phase1/unknown-form', file: 'phase1/unknown-ats-form.html', note: 'An accessible form on an unknown ATS: unsupported before, complete now.' },
    { name: 'generic-greenhouse-style-partial', route: '/u/greenhouse-style-form', file: 'universal/forms/greenhouse-style-form.html', note: 'A form with controls a label points to that are not in the page: partial, never complete.' },
];

const normalise = (result, origin) => {
    const text = JSON.stringify(result).split(origin).join('http://fixture.test');

    return JSON.parse(text, (k, v) => (['total_ms', 'navigation_ms', 'extraction_ms'].includes(k) ? 0 : k === 'timestamp' ? '1970-01-01T00:00:00.000Z' : v));
};

export async function buildSnapshots() {
    const routes = Object.fromEntries(SNAPSHOTS.map((s) => [s.route, () => ({ body: fixture(s.file) })]));
    const sites = await startSites({ board: routes });
    try {
        const out = {};
        for (const s of SNAPSHOTS) {
            const result = await inspect(`${sites.origins.board}${s.route}`, { guard: sites.guard(), launch: sites.launch(), timeouts: { settleMs: 100, networkIdleMs: 800 }, budgetMs: 60_000, genericDiscovery: true });
            out[s.name] = normalise({ _note: s.note, ...result }, sites.origins.board);
        }

        return out;
    } finally {
        await sites.close();
    }
}
