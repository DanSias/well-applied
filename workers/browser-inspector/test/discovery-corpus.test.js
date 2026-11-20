// M1.3: generic discovery against the M0 corpus: the acceptance contract. Offline; Chromium only READS fixtures loaded with
// setContent. Every functional criterion (F1-F10) and every safety/quality measurement that applies at discovery level is
// asserted from m1-criteria.json, not restated here.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { loadCorpus, loadCriteria, loadExpected, scoreFixture, aggregate, verdicts, runOnFixture } from './support/discovery-score.js';
import { discoverForm } from '../src/discovery/index.js';
import { readOnlyPage } from '../src/discovery/page-adapter.js';
import { toScorable } from '../src/discovery/contract.js';

const corpus = loadCorpus();
const criteria = loadCriteria();
const FORMS = corpus.fixtures.filter((f) => criteria.applies_to.includes(f.id) && f.kind === 'form');
const discover = async (page) => {
    const result = await discoverForm(readOnlyPage(page));

    return { rows: result.controls.map(toScorable), result };
};

describe('generic discovery meets the M1 criteria on the M0 corpus', () => {
    let browser;
    const runs = new Map();

    before(async () => {
        browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
        for (const fixture of FORMS) {
            const expected = loadExpected(fixture);
            const run = await runOnFixture(browser, fixture, discover);
            runs.set(fixture.id, { fixture, expected, run, counters: await scoreFixture({ fixtureId: fixture.id, expected, rows: run.rows, page: run.page }) });
        }
    });
    after(async () => {
        for (const r of runs.values()) await r.run.close();
        await browser?.close();
    });

    const all = () => [...runs.values()];
    const get = (id) => runs.get(id).run.out.result;

    test('functional criteria F1-F10 all pass (and the table is printed)', () => {
        const v = verdicts(aggregate(all().map((r) => r.counters)), criteria);
        const fmt = (x) => (Number.isInteger(x) ? String(x) : x.toFixed(3));
        console.log('\n  GENERIC DISCOVERY vs M1 criteria');
        for (const x of v) console.log(`   ${x.pass ? 'pass' : 'FAIL'}  ${x.id.padEnd(13)} ${x.name.padEnd(42)} ${fmt(x.value).padStart(6)}  (needs ${x.threshold})`);
        const detail = all().flatMap((r) => [...r.counters.missing.map((m) => `${r.fixture.id}: MISSING ${m}`), ...r.counters.extra_rows.map((m) => `${r.fixture.id}: EXTRA ${m}`), ...r.counters.integrity.map((m) => `${r.fixture.id}: INTEGRITY ${m}`)]);
        if (detail.length) console.log('   ' + detail.join('\n   '));
        assert.deepEqual(v.filter((x) => !x.pass).map((x) => `${x.id} ${x.value} (needs ${x.threshold})`), []);
    });

    test('every fixture is perfect on its own, not just in aggregate', () => {
        for (const r of all()) {
            const c = r.counters;
            assert.equal(c.matched, c.expected_total, `${r.fixture.id}: missing ${c.missing}`);
            assert.equal(c.label_ok, c.matched, `${r.fixture.id}: labels`);
            assert.equal(c.required_ok, c.matched, `${r.fixture.id}: required`);
            assert.equal(c.kind_ok, c.matched, `${r.fixture.id}: kinds`);
            assert.deepEqual(c.integrity, [], r.fixture.id);
            assert.deepEqual(c.extra_rows, [], r.fixture.id);
        }
    });

    describe('read-only, offline, deterministic (M1-S1..S5, Q1, Q2)', () => {
        test('no request is made and the page is not modified', () => {
            for (const r of all()) {
                assert.deepEqual(r.run.safety.requests, [], `${r.fixture.id} made requests`);
                assert.equal(r.run.safety.domChanged, false, `${r.fixture.id}: the page was modified`);
            }
        });
        test('nothing was activated: the traps stay armed', async () => {
            for (const r of all()) assert.ok([null, 0].includes(r.run.safety.trapFired), r.fixture.id);
        });
        test('each fixture is discovered in well under the 5 s budget', () => {
            for (const r of all()) assert.ok(r.run.safety.ms <= 5000, `${r.fixture.id}: ${r.run.safety.ms} ms`);
        });
        test('discovery is deterministic: the same page always yields the same result', async () => {
            for (const r of all()) {
                const again = await runOnFixture(browser, r.fixture, discover);
                assert.deepEqual(again.out.result.controls, r.run.out.result.controls, r.fixture.id);
                assert.deepEqual(again.out.result.coverage, r.run.out.result.coverage, r.fixture.id);
                await again.close();
            }
        });
    });

    describe('the Greenhouse-style replica is understood the way the golden says', () => {
        const byId = (id) => get('greenhouse-style-form').controls.find((c) => c.id === id);

        test('Country is ONE control that owns its hidden validation input', () => {
            const country = byId('country');
            assert.equal(get('greenhouse-style-form').controls.filter((c) => c.id === 'country' || c.id === null).length, 1);
            assert.deepEqual([country.kind, country.widget, country.required], ['select', 'combobox', { value: true, basis: 'aria_required' }]);
            assert.deepEqual(country.interaction.auxiliary.map((a) => a.role), ['validation_input']);
            assert.equal(country.interaction.mechanism, 'open_listbox_and_choose');
        });
        test('Resume/CV and Cover Letter are distinct optional file inputs, named from their headings and flagged inferred', () => {
            for (const [id, label] of [['resume', 'Resume/CV'], ['cover_letter', 'Cover Letter']]) {
                const c = byId(id);
                assert.deepEqual([c.kind, c.label.text, c.label.basis, c.label.confidence, c.label.generic_name, c.required.value], ['file', label, 'heading_context', 'inferred', 'Attach', false]);
                assert.equal(c.widget, 'native_input_hidden_behind_button');
            }
        });
        test('every dropdown is a selection, with options honestly "not in the page"', () => {
            const combos = get('greenhouse-style-form').controls.filter((c) => c.widget === 'combobox');
            assert.equal(combos.length, 10);
            for (const c of combos) assert.deepEqual([c.kind, c.options.state], ['select', 'not_in_observed_dom']);
        });
        test('the nine demographic and EEOC questions are sensitive, and nothing else is', () => {
            const flagged = get('greenhouse-style-form').controls.filter((c) => c.sensitive.value).map((c) => c.id).sort();
            assert.deepEqual(flagged, ['d_disability', 'd_gender_identity', 'd_orientation', 'd_racial_ethnic', 'd_transgender', 'd_veteran', 'gender', 'hispanic_ethnicity', 'veteran_status']);
        });
        test('Race, which exists only in inert page data, is not invented', () => {
            assert.ok(!get('greenhouse-style-form').controls.some((c) => /^race$/i.test(c.label.text ?? '')));
        });
        test('the manual text boxes are known-but-absent, so the result is partial, not complete', () => {
            const r = get('greenhouse-style-form');
            assert.deepEqual(r.coverage.known_uninspected, [{ kind: 'referenced_controls_absent', count: 2, ids: ['resume_text', 'cover_letter_text'] }]);
            assert.deepEqual([r.inspection_outcome, r.stop_reason], ['partial', null]);
            assert.deepEqual(r.warnings, ['conditional_fields_not_inspected']);
        });
    });

    describe('the other adversarial fixtures', () => {
        test('combobox-nested-search: one control each; the nested search box and mirror inputs are auxiliary', () => {
            const r = get('combobox-nested-search');
            assert.deepEqual(r.controls.map((c) => [c.id, c.kind, c.widget]), [['cb-residence', 'select', 'combobox'], ['btn-language', 'select', 'listbox_button'], ['cb-timezone', 'select', 'combobox'], ['work-mode', 'select', 'native_select']]);
            assert.deepEqual(r.controls[1].interaction.auxiliary.map((a) => a.role), ['popup_search']);
            assert.deepEqual(r.controls[2].interaction.auxiliary.map((a) => a.role).sort(), ['mirror_input', 'validation_input']);
            assert.deepEqual(r.controls[0].options, { state: 'known_hidden_in_dom', count: 4, labels: ['Canada', 'Germany', 'United Kingdom', 'United States'] });
            assert.equal(r.inspection_outcome, 'complete');
        });
        test('labels-vs-headings: where each label came from, and how sure we are', () => {
            const r = get('labels-vs-headings');
            const by = Object.fromEntries(r.controls.map((c) => [c.id, [c.label.text, c.label.basis, c.label.confidence]]));
            assert.deepEqual(by['up-resume'], ['Resume', 'heading_context', 'inferred']);
            assert.deepEqual(by['up-portfolio'], ['Portfolio', 'heading_context', 'inferred']);
            assert.deepEqual(by['why-us'], ['Why do you want to work here?', 'placeholder', 'inferred']);
            assert.deepEqual(by['auth'], ['Are you legally authorized to work in the United States?', 'accessible_name', 'structural']);
            assert.deepEqual(by['skill'], ['Which of these have you used?', 'accessible_name', 'structural']);
            assert.deepEqual(by['start-date'], ['Earliest start date', 'proximity_text', 'inferred']);
            assert.deepEqual(by['salary'], ['Desired salary (USD)', 'accessible_name', 'structural']);
        });
        test('conditional-hidden-fields: hidden dependents are known but not reported; the script-created field does not exist', () => {
            const r = get('conditional-hidden-fields');
            assert.deepEqual(r.controls.map((c) => c.id), ['full-name', 'visa', 'hear', 'region']);
            assert.deepEqual(r.coverage.known_uninspected, [{ kind: 'hidden_fields_in_markup', count: 2 }]);
            assert.equal(r.inspection_outcome, 'partial');
            assert.ok(!r.controls.some((c) => c.id === 'province'));
        });
        test('sensitive-demographics: five sensitive, four decoys not', () => {
            const r = get('sensitive-demographics');
            const flagged = r.controls.filter((c) => c.sensitive.value).map((c) => c.id).sort();
            assert.deepEqual(flagged, ['disability', 'sid-gender', 'sid-orientation', 'sid-race', 'sid-veteran']);
            for (const id of ['work-auth', 'vets-program', 'a11y', 'salary', 'name']) assert.equal(r.controls.find((c) => c.id === id).sensitive.value, false, id);
            assert.equal(r.inspection_outcome, 'complete');
        });
        test('multi-step: step 1 is read, the rest is known but not inspected, so it is never "complete"', () => {
            const r = get('multi-step');
            assert.deepEqual(r.controls.map((c) => c.id), ['s1-first', 's1-last', 's1-email']);
            assert.deepEqual(r.coverage.known_uninspected, [{ kind: 'hidden_fields_in_markup', count: 2 }, { kind: 'more_steps', current: 1, total: 3 }]);
            assert.deepEqual(r.warnings, ['conditional_fields_not_inspected', 'more_steps_not_inspected']);
            assert.equal(r.inspection_outcome, 'partial');
        });
        test('unknown-ats-form: an ordinary accessible form is read completely', () => {
            const r = get('unknown-ats-form');
            assert.equal(r.inspection_outcome, 'complete');
            assert.deepEqual(r.fields.map((f) => [f.raw_label, f.control_type, f.required]), [['First name', 'text', true], ['Last name', 'text', true], ['Email', 'email', true], ['Phone', 'tel', false], ['Resume', 'file', true], ['Why do you want to work here?', 'textarea', false]]);
        });
    });
});
