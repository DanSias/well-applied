// Ground truth, normalised from evidence that already exists in the repository. Nothing is re-derived from an extractor:
// the adapters only re-shape what the corpus, the golden and the DOM observation already say, keeping each fact's class.
//
// Classes (never mixed, never upgraded):
//   observed    seen directly on a page / in a stored run
//   api_derived stated by a provider's published data
//   authored    defined by a synthetic fixture's own construction
//   inferred    concluded by reasoning; scored separately, never as firm truth
//   unknown     not established; never scored
import { readVerifiedJson } from './evidence.js';

export const FIRM = new Set(['observed', 'api_derived', 'authored']);
export const KINDS = new Set(['text', 'email', 'tel', 'textarea', 'file', 'select', 'radio_group', 'checkbox_group']);

const fact = (v, c) => (v === undefined || v === null ? null : { v, c });

/** The synthetic corpus: expectations authored together with the fixture. */
function universalExpected(verified, root) {
    const e = readVerifiedJson(verified, 'expected', root).m1;
    const fields = (e.fields ?? []).map((f) => ({
        key: f.match.dom_ids[0],
        ids: f.match.dom_ids,
        label: f.match.label,
        label_truncated: false,
        exists: 'authored',
        kind: fact(f.kind, 'authored'),
        required: fact(f.required, 'authored'),
        scorable: true,
    }));

    return {
        exhaustive: { v: true, c: 'authored', basis: 'the fixture is small and its expected file lists every control the static page defines; script-created controls are excluded by design' },
        fields,
        allowed_outcomes: { v: e.outcome_any_of ?? [e.outcome], c: 'authored' },
        stop_reason: { v: e.stop_reason ?? null, c: 'authored' },
        provenance: { allowed: ['dom', 'aria', 'both'], c: 'authored' },
    };
}

/** The Chainguard golden: observed rows, the provider API and explicit inferences, each tagged. */
function chainguardGolden(verified, root) {
    const g = readVerifiedJson(verified, 'golden', root);
    const fields = g.fields.map((f) => {
        const base = { key: f.id, ids: [...new Set([...(f.observed?.dom_ids ?? []), ...(f.api?.names ?? [])])], label: f.facts?.label?.v ?? null, label_truncated: false };
        if (f.status !== 'present') return { ...base, exists: 'unknown', kind: null, required: null, scorable: false, reason: `golden status ${f.status}: not established as a rendered control` };

        return { ...base, exists: 'observed', kind: fact(f.facts.kind?.v, f.facts.kind?.c), required: fact(f.facts.required?.v, f.facts.required?.c), scorable: true };
    });

    return {
        exhaustive: { v: true, c: 'observed', basis: 'the golden accounts for every control the one observed page rendered; fields the API lists but the page did not render are marked non-scorable' },
        fields,
        provenance: { allowed: ['dom', 'aria', 'both'], c: 'observed' },
    };
}

/** NerdWallet: what a person's own Chrome showed. Independent of the schema the result came from, but NOT an exhaustive enumeration. */
function ashbyDom(verified, root) {
    const d = readVerifiedJson(verified, 'dom_observation', root);
    const fields = d.visible_controls.map((c, i) => {
        const key = `dom-${String(i).padStart(2, '0')}`;
        if (!c.label || c.type === 'combobox') return { key, ids: [], label: c.label ?? null, label_truncated: false, exists: 'observed', kind: null, required: null, scorable: false, reason: 'the observation could not resolve a question label for this control' };

        return { key, ids: [], label: c.label, label_truncated: c.label.length >= 90, exists: 'observed', kind: fact(c.type, 'observed'), required: fact(c.required, 'observed'), scorable: true };
    });

    return {
        exhaustive: { v: false, c: 'observed', basis: 'the observation lists visible controls only: seven Yes/No pairs have no resolved label and the survey groups were collapsed, so extra result rows cannot be called wrong' },
        fields,
        provenance: { allowed: ['provider_schema'], c: 'authored' },
    };
}

const ADAPTERS = { universal_expected: universalExpected, chainguard_golden: chainguardGolden, ashby_dom: ashbyDom };

/** @returns the normalised truth for a case, with its manifest `expect` block merged over the adapter's output. */
export function buildTruth(testCase, verified, root) {
    const adapter = ADAPTERS[testCase.truth?.adapter];
    if (!adapter) throw new Error(`unknown truth adapter ${testCase.truth?.adapter}`);
    const t = adapter(verified, root);
    const x = testCase.expect ?? {};

    return {
        ...t,
        platform: x.platform ?? null,
        application_url: x.application_url ?? null,
        allowed_outcomes: x.allowed_outcomes ?? t.allowed_outcomes ?? null,
        stop_reason: x.stop_reason ?? t.stop_reason ?? null,
        provenance: x.provenance ?? t.provenance ?? null,
    };
}
