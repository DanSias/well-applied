// The nine synthetic cases that are REPLAYED through the real inspect() pipeline, over local fixture servers only.
// Shared by the refresh tool and the drift test, so a committed result is always reproducible from the committed fixture.
import { inspect } from '../../src/inspect.js';
import { startSites, fixture } from '../../test/support/fixture-server.js';

export const REPLAY = [
    { id: 'syn-greenhouse-style-form', route: '/u/greenhouse-style-form', file: 'universal/forms/greenhouse-style-form.html' },
    { id: 'syn-combobox-nested-search', route: '/u/combobox-nested-search', file: 'universal/forms/combobox-nested-search.html' },
    { id: 'syn-labels-vs-headings', route: '/u/labels-vs-headings', file: 'universal/forms/labels-vs-headings.html' },
    { id: 'syn-conditional-hidden-fields', route: '/u/conditional-hidden-fields', file: 'universal/forms/conditional-hidden-fields.html' },
    { id: 'syn-sensitive-demographics', route: '/u/sensitive-demographics', file: 'universal/forms/sensitive-demographics.html' },
    { id: 'syn-multi-step', route: '/u/multi-step', file: 'universal/forms/multi-step.html' },
    { id: 'syn-unknown-ats-form', route: '/phase1/unknown-form', file: 'phase1/unknown-ats-form.html' },
    { id: 'syn-auth-gate-aggregator', route: '/auth-gate-aggregator.html', file: 'universal/forms/auth-gate-aggregator.html' },
    { id: 'syn-apply-button-nonget', route: '/u/apply-button-nonget', file: 'universal/forms/apply-button-nonget.html' },
];

/** The two inspector configurations the harness compares. `generic-off` is what production runs today. */
export const CONFIGS = { 'generic-off': { genericDiscovery: false }, 'generic-on': { genericDiscovery: true } };

const SYNTHETIC_ORIGIN = 'http://fixture.test';

// No ports, timings or timestamps: a result must be the same bytes on every machine and run.
const normalise = (result, origin) =>
    JSON.parse(JSON.stringify(result).split(origin).join(SYNTHETIC_ORIGIN), (k, v) => (['total_ms', 'navigation_ms', 'extraction_ms'].includes(k) ? 0 : k === 'timestamp' ? '1970-01-01T00:00:00.000Z' : v));

/** @returns {Promise<Record<string, Record<string, object>>>} config -> case id -> normalised InspectionResult */
export async function replayAll(configs = Object.keys(CONFIGS)) {
    const sites = await startSites({ board: Object.fromEntries(REPLAY.map((c) => [c.route, () => ({ body: fixture(c.file) })])) });
    try {
        const out = {};
        for (const name of configs) {
            out[name] = {};
            for (const c of REPLAY) {
                const r = await inspect(`${sites.origins.board}${c.route}`, { guard: sites.guard(), launch: sites.launch(), timeouts: { settleMs: 100, networkIdleMs: 800 }, budgetMs: 60_000, ...CONFIGS[name] });
                out[name][c.id] = normalise(r, sites.origins.board);
            }
        }

        return out;
    } finally {
        await sites.close();
    }
}
