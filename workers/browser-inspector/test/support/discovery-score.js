// The M1 acceptance contract, executable. Scores a list of discovered rows against an M0 expected file using the matching
// rule and the metrics in test/fixtures/universal/m1-criteria.json. Test support only: nothing here ships in the worker.
//
// A "scorable row" is the common shape every discoverer is reduced to before scoring:
//   { key, label, kind, required, sensitive, label_confidence, options: {state, count}|null, css }
// `key` is the DOM id (or, for groups, the name) of the control; `css` is a selector for its primary element, used to ask
// "is this row one of the elements that must not be reported?".
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const UNIVERSAL = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'universal');
export const loadCorpus = () => JSON.parse(readFileSync(path.join(UNIVERSAL, 'corpus.json'), 'utf8'));
export const loadCriteria = () => JSON.parse(readFileSync(path.join(UNIVERSAL, 'm1-criteria.json'), 'utf8'));
export const loadExpected = (fixture) => JSON.parse(readFileSync(path.join(UNIVERSAL, fixture.expected), 'utf8'));
export const readFixtureHtml = (fixture) => readFileSync(path.join(UNIVERSAL, fixture.file), 'utf8');

/** The criteria's normalisation: lower-case, collapse whitespace, strip the required marker, trim. */
export const normaliseLabel = (s) => (s ?? '').toLowerCase().replace(/\s+/g, ' ').replace(/\*+\s*$/, '').trim();

/**
 * Which row, if any, is each expected field? A row matches when its key is one of match.dom_ids; failing that, when its
 * normalised label equals the expected one. A row matches at most one expected field.
 */
export function matchRows(expectedFields, rows) {
    const taken = new Set();
    const pairs = [];
    const missing = [];

    for (const exp of expectedFields) {
        const wanted = new Set(exp.match.dom_ids);
        let hit = rows.findIndex((r, i) => !taken.has(i) && r.key !== null && wanted.has(r.key));
        if (hit === -1) hit = rows.findIndex((r, i) => !taken.has(i) && normaliseLabel(r.label) === normaliseLabel(exp.match.label));

        if (hit === -1) {
            missing.push(exp);
        } else {
            taken.add(hit);
            pairs.push({ exp, row: rows[hit], index: hit });
        }
    }

    return { pairs, missing, unmatchedRows: rows.map((r, i) => ({ r, i })).filter(({ i }) => !taken.has(i)) };
}

/** In the page: which of these CSS selectors name elements that match `selector`? (identity, not text) */
async function elementsMatching(page, selector, rowCss) {
    return page.evaluate(
        ({ selector: sel, rowCss: css }) => {
            const targets = new Set(document.querySelectorAll(sel));

            return css.map((c) => {
                if (!c) return false;
                try {
                    const el = document.querySelector(c);

                    return el !== null && targets.has(el);
                } catch {
                    return false;
                }
            });
        },
        { selector, rowCss },
    );
}

/**
 * Scores one fixture. `page` must be the page the rows were discovered on (it is only read, to resolve must_not_report).
 * @returns {Promise<object>} raw counters; `aggregate()` turns them into the criteria's metrics
 */
export async function scoreFixture({ fixtureId, expected, rows, page }) {
    const fields = expected.m1.fields;
    const { pairs, missing, unmatchedRows } = matchRows(fields, rows);
    const c = {
        fixture: fixtureId, expected_total: fields.length, matched: pairs.length, missing: missing.map((m) => m.match.label), rows_total: rows.length,
        label_ok: 0, required_ok: 0, kind_ok: 0, select_total: 0, select_ok: 0,
        sensitive_expected: 0, sensitive_flagged: 0, sensitive_false_total: 0, sensitive_false_positives: 0,
        confidence_expected_inferred: 0, confidence_violations: 0, label_must_not_be_violations: 0,
        options_total: 0, options_ok: 0, extra_rows: [], integrity: [],
    };

    for (const { exp, row } of pairs) {
        if (normaliseLabel(row.label) === normaliseLabel(exp.label)) c.label_ok += 1;
        if (row.required === exp.required) c.required_ok += 1;
        if (row.kind === exp.kind) c.kind_ok += 1;
        if (exp.kind === 'select') {
            c.select_total += 1;
            if (row.kind === 'select') c.select_ok += 1;
        }
        if (exp.sensitive === true) {
            c.sensitive_expected += 1;
            if (row.sensitive === true) c.sensitive_flagged += 1;
        }
        if (exp.sensitive === false) {
            c.sensitive_false_total += 1;
            if (row.sensitive === true) c.sensitive_false_positives += 1;
        }
        if (exp.label_confidence === 'inferred') {
            c.confidence_expected_inferred += 1;
            if (row.label_confidence === 'structural') c.confidence_violations += 1;
        }
        if (exp.label_must_not_be && normaliseLabel(row.label) === normaliseLabel(exp.label_must_not_be)) c.label_must_not_be_violations += 1;
        if (exp.options) {
            c.options_total += 1;
            const got = row.options;
            const stateOk = got?.state === exp.options.state;
            const countOk = !/^known_/.test(exp.options.state) || got?.count === exp.options.count;
            if (stateOk && countOk) c.options_ok += 1;
        }
    }

    // One logical control must not be several rows.
    for (const exp of fields) {
        const wanted = new Set(exp.match.dom_ids);
        const rowsForIt = rows.filter((r) => r.key !== null && wanted.has(r.key));
        const allowed = exp.max_rows ?? 1;
        if (rowsForIt.length > allowed) c.integrity.push(`${exp.match.label}: ${rowsForIt.length} rows (max ${allowed})`);
    }

    // Rows that must never appear.
    for (const entry of expected.m1.must_not_report ?? []) {
        let hits = [];
        if (entry.dom_selector && page) {
            const flags = await elementsMatching(page, entry.dom_selector, rows.map((r) => r.css ?? null));
            hits = rows.filter((_, i) => flags[i]);
        } else {
            hits = rows.filter((r) => r.key === entry.id);
        }
        for (const h of hits) c.integrity.push(`${entry.id}: reported "${h.label ?? h.key}"`);
    }

    c.extra_rows = unmatchedRows.map(({ r }) => r.label ?? r.key ?? '(unlabelled)');
    c.sensitive_flags_total = rows.filter((r) => r.sensitive === true).length;

    return c;
}

const sum = (xs, k) => xs.reduce((a, x) => a + x[k], 0);
const ratio = (n, d) => (d === 0 ? 1 : n / d);

/** The criteria's functional metrics over a set of per-fixture counters (non-form fixtures contribute no fields). */
export function aggregate(counters) {
    const form = counters.filter((c) => c.expected_total > 0);
    const m = {
        'M1-F1': ratio(sum(form, 'matched'), sum(form, 'expected_total')),
        'M1-F1-floor': Math.min(...form.map((c) => ratio(c.matched, c.expected_total))),
        'M1-F2': ratio(sum(form, 'label_ok'), sum(form, 'matched')),
        'M1-F3': ratio(sum(form, 'required_ok'), sum(form, 'matched')),
        'M1-F4': ratio(sum(form, 'kind_ok'), sum(form, 'matched')),
        'M1-F4-select': ratio(sum(form, 'select_ok'), sum(form, 'select_total')),
        'M1-F5': sum(counters, 'integrity') ,
        'M1-F6': ratio(counters.reduce((a, c) => a + c.extra_rows.length, 0), sum(counters, 'rows_total')),
        'M1-F6-max-per-fixture': Math.max(...counters.map((c) => c.extra_rows.length)),
        'M1-F7': ratio(sum(form, 'sensitive_flagged'), sum(form, 'sensitive_expected')),
        'M1-F8': sum(form, 'sensitive_false_positives'),
        'M1-F9': sum(form, 'confidence_violations') + sum(form, 'label_must_not_be_violations'),
        'M1-F10': ratio(sum(form, 'options_ok'), sum(form, 'options_total')),
    };
    m['M1-F5'] = counters.reduce((a, c) => a + c.integrity.length, 0);

    return m;
}

/** ">= 0.95", "<= 0.02", "== 1.00", "== 0": is `value` within the threshold? */
export function meets(value, threshold) {
    const t = /^(>=|<=|==)\s*([0-9.]+)/.exec(threshold);
    if (!t) throw new Error(`unparseable threshold: ${threshold}`);
    const n = Number(t[2]);
    const eps = 1e-9;

    return t[1] === '>=' ? value >= n - eps : t[1] === '<=' ? value <= n + eps : Math.abs(value - n) < eps;
}

/** Every functional metric, with its measured value, threshold and verdict, from the criteria file. */
export function verdicts(metrics, criteria) {
    const byId = Object.fromEntries(criteria.functional.map((f) => [f.id, f]));
    const out = [];
    const push = (id, name, value, threshold) => out.push({ id, name, value, threshold, pass: meets(value, threshold) });

    push('M1-F1', byId['M1-F1'].name, metrics['M1-F1'], byId['M1-F1'].threshold);
    push('M1-F1 floor', 'per-fixture recall floor', metrics['M1-F1-floor'], byId['M1-F1'].per_fixture_floor);
    push('M1-F2', byId['M1-F2'].name, metrics['M1-F2'], byId['M1-F2'].threshold);
    push('M1-F3', byId['M1-F3'].name, metrics['M1-F3'], byId['M1-F3'].threshold);
    push('M1-F4', byId['M1-F4'].name, metrics['M1-F4'], byId['M1-F4'].threshold);
    push('M1-F4 select', 'every dropdown is kind select', metrics['M1-F4-select'], '== 1.00');
    push('M1-F5', byId['M1-F5'].name, metrics['M1-F5'], byId['M1-F5'].threshold);
    push('M1-F6', byId['M1-F6'].name, metrics['M1-F6'], byId['M1-F6'].threshold);
    push('M1-F6 cap', 'extra rows per fixture', metrics['M1-F6-max-per-fixture'], byId['M1-F6'].per_fixture_cap);
    push('M1-F7', byId['M1-F7'].name, metrics['M1-F7'], byId['M1-F7'].threshold);
    push('M1-F8', byId['M1-F8'].name, metrics['M1-F8'], byId['M1-F8'].threshold);
    push('M1-F9', byId['M1-F9'].name, metrics['M1-F9'], byId['M1-F9'].threshold);
    push('M1-F10', byId['M1-F10'].name, metrics['M1-F10'], byId['M1-F10'].threshold);

    return out;
}

// ---------------------------------------------------------------------------------------------------------------------
// Running a discoverer over a fixture, and measuring the safety criteria while it does.

/**
 * Loads one fixture with setContent (no network), runs `discover(page)` and measures M1-S3/S4/S5 and speed.
 * `discover` returns scorable rows (or an object whose `.rows` are).
 */
export async function runOnFixture(browser, fixture, discover) {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    const requests = [];
    await context.route('**/*', (route) => {
        requests.push(`${route.request().method()} ${route.request().url()}`);

        return route.abort();
    });
    const page = await context.newPage();
    await page.setContent(readFixtureHtml(fixture));
    const before = await page.evaluate(() => document.documentElement.outerHTML);
    const startedAt = Date.now();
    const out = await discover(page);
    const ms = Date.now() - startedAt;
    const after = await page.evaluate(() => document.documentElement.outerHTML);
    const trap = await page.evaluate(() => (window.__trap ? window.__trap.fired : null));

    return {
        page,
        close: () => context.close(),
        out,
        rows: Array.isArray(out) ? out : out.rows,
        safety: { requests, domChanged: before !== after, trapFired: trap, ms },
    };
}
