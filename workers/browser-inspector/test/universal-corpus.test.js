// M0 of Universal Application Inspection: the fixture corpus, the golden expectations and the M1 criteria are what they say
// they are. Strictly offline and deterministic: no network, no AI box, no database, no live Greenhouse page. The browser is
// used only to READ fixtures loaded with setContent (nothing is clicked or typed), and every request it would make is counted.
// See test/fixtures/universal/README.md.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'universal');
const at = (...p) => path.join(ROOT, ...p);
const json = (...p) => JSON.parse(readFileSync(at(...p), 'utf8'));
const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');
const normalise = (s) => (s ?? '').toLowerCase().replace(/\s+/g, ' ').replace(/\*+\s*$/, '').trim();

const EVIDENCE = 'evidence/chainguard-4722145006';
const manifest = json(EVIDENCE, 'MANIFEST.json');
const rows = json(EVIDENCE, 'run12.application_questions.json');
const run = json(EVIDENCE, 'run12.agent_run.json')[0];
const api = json(EVIDENCE, 'greenhouse-api-schema.json');
const statics = json(EVIDENCE, 'static-page-observations.json');
const golden = json('golden', 'chainguard-4722145006.semantic.json');
const corpus = json('corpus.json');
const criteria = json('m1-criteria.json');
const expectedOf = (f) => json(f.expected);

const CLASSES = ['observed', 'api_derived', 'inferred', 'unknown'];
const byField = (id) => golden.fields.find((f) => f.id === id);

// ---------------------------------------------------------------------------------------------------------------------
describe('the run #12 evidence is preserved, unmodified', () => {
    test('every evidence file matches the hash recorded when it was captured', () => {
        for (const [file, meta] of Object.entries(manifest.files)) {
            assert.equal(sha256(readFileSync(at(EVIDENCE, file))), meta.sha256, `${file} has changed: evidence is immutable`);
        }
    });

    test('only the API capture is trimmed, and it says exactly what was removed', () => {
        const modified = Object.entries(manifest.files).filter(([, m]) => m.modified).map(([f]) => f);
        assert.deepEqual(modified, ['greenhouse-api-schema.json']);
        assert.match(manifest.files['greenhouse-api-schema.json'].modification, /'content'/);
        assert.ok(!('content' in api), 'the job description prose is not stored');
        assert.match(manifest.files['greenhouse-api-schema.json'].original_sha256, /^[0-9a-f]{64}$/);
    });

    test('the observed baseline is what run #12 recorded (documentation of the baseline, not a target for M1)', () => {
        assert.equal(run.id, 12);
        assert.equal(run.inspection_outcome, 'complete');
        assert.equal(run.ats_detected, 'greenhouse');
        assert.deepEqual(rows.map((r) => r.position), [...rows.keys()], 'positions are contiguous from 0');
        assert.ok(rows.every((r) => r.agent_run_id === 12));
    });

    test('the page observations are facts, not the page: no HTML is stored', () => {
        assert.match(statics.what_this_is, /no JavaScript executed/);
        assert.equal(statics.http_status, 200);
        assert.ok(!existsSync(at(EVIDENCE, 'page.html')));
    });
});

// ---------------------------------------------------------------------------------------------------------------------
describe('the semantic golden keeps observed, API-derived, inferred and unknown apart', () => {
    const facts = golden.fields.flatMap((f) => Object.entries(f.facts).map(([name, fact]) => ({ field: f.id, name, fact })));

    test('declares the four evidence classes and uses only those', () => {
        assert.deepEqual(Object.keys(golden.evidence_classes), CLASSES);
        for (const { field, name, fact } of facts) {
            assert.ok(CLASSES.includes(fact.c), `${field}.${name} has an unknown evidence class ${fact.c}`);
        }
    });

    test('every assertion names its source, and an inferred one says on what it rests', () => {
        for (const { field, name, fact } of facts) {
            assert.equal(typeof fact.src, 'string', `${field}.${name} has no source`);
            assert.ok(fact.src.length > 8, `${field}.${name} source is too thin`);
        }
        for (const { field, name, fact } of facts.filter((x) => x.fact.c === 'inferred')) {
            assert.ok(fact.src.length > 20 || fact.note, `${field}.${name} is inferred without a stated basis`);
        }
    });

    test('an unknown is never given a value', () => {
        for (const { field, name, fact } of facts.filter((x) => x.fact.c === 'unknown')) {
            const v = fact.v;
            const empty = v === null || v === 'unknown' || (typeof v === 'object' && Object.values(v).every((x) => x === null || x === 'not_in_observed_dom'));
            assert.ok(empty, `${field}.${name} is unknown but carries the value ${JSON.stringify(v)}`);
        }
    });

    test('nothing inferred or unknown is marked confirmed or verified', () => {
        for (const f of golden.fields) {
            for (const [name, fact] of Object.entries(f.facts)) {
                if (fact.c === 'inferred' || fact.c === 'unknown') {
                    assert.ok(!('confirmed' in fact) && !('verified' in fact), `${f.id}.${name}`);
                }
            }
        }
        assert.ok(!/(?<!not )(?<!un)\bconfirmed\b/i.test(JSON.stringify(golden.fields.flatMap((f) => Object.values(f.facts).filter((x) => x.c !== 'observed' && x.c !== 'api_derived')))), 'an inferred or unknown fact claims confirmation');
    });

    test('every field uses the shared vocabulary', () => {
        for (const f of golden.fields) {
            assert.ok(golden.vocabulary.status.includes(f.status), f.id);
            assert.ok(corpus.vocabulary.kind.includes(f.facts.kind.v), `${f.id} kind ${f.facts.kind.v}`);
            if (f.facts.widget) assert.ok(corpus.vocabulary.widget.includes(f.facts.widget.v), `${f.id} widget`);
            if (f.facts.cardinality) assert.ok(corpus.vocabulary.cardinality.includes(f.facts.cardinality.v), `${f.id} cardinality`);
        }
    });

    describe('the points you asked for', () => {
        test('Country is ONE logical control; the extra run #12 row is recorded as an inference, not a field', () => {
            assert.equal(golden.fields.filter((f) => f.id === 'country').length, 1);
            const country = byField('country');
            assert.deepEqual(country.observed.rows, [3]);
            assert.deepEqual(country.observed.duplicate_rows.map((d) => [d.row, d.c]), [[4, 'inferred']]);
            assert.ok(!golden.fields.some((f) => f.observed?.rows?.includes(4)), 'row 4 is not its own field');
            assert.equal(country.facts.widget.v, 'combobox');
            assert.equal(country.facts.kind.v, 'select');
        });

        test('Resume/CV and Cover Letter are distinct, optional file inputs, labelled for what they are', () => {
            for (const [id, label, other] of [['resume', 'Resume/CV', 'cover_letter'], ['cover_letter', 'Cover Letter', 'resume']]) {
                const f = byField(id);
                assert.equal(f.facts.kind.v, 'file');
                assert.equal(f.facts.required.v, false);
                assert.equal(f.facts.label.v, label);
                assert.equal(f.facts.dom_accessible_label.v, 'Attach', 'the DOM label is recorded separately, as observed');
                assert.equal(f.facts.distinct_from.v, other);
            }
            assert.notDeepEqual(byField('resume').observed.rows, byField('cover_letter').observed.rows);
        });

        test('every dropdown is a selection control, whatever the widget', () => {
            const combos = golden.fields.filter((f) => f.facts.widget?.v === 'combobox');
            assert.ok(combos.length >= 10);
            for (const f of combos) assert.equal(f.facts.kind.v, 'select', f.id);
        });

        test('demographic and EEOC questions are sensitive; core fields are not; the basis is kept honest', () => {
            const sensitiveGroups = ['U.S. Standard Demographic Questions', 'Voluntary Self-Identification'];
            for (const f of golden.fields) {
                assert.equal(f.facts.sensitive.v, sensitiveGroups.includes(f.group), `${f.id} sensitive`);
            }
            assert.equal(byField('hispanic_ethnicity').facts.sensitive.c, 'inferred', 'it has no API entry of its own');
            assert.equal(byField('gender').facts.sensitive.c, 'api_derived');
        });

        test('the manual resume and cover-letter text boxes are unobserved conditional content, with an INFERRED trigger', () => {
            for (const id of ['resume_text', 'cover_letter_text']) {
                const f = byField(id);
                assert.equal(f.status, 'unobserved');
                assert.equal(f.observed, null);
                assert.equal(f.facts.conditional.v, 'unobserved_conditional');
                assert.equal(f.facts.conditional.c, 'inferred');
                assert.equal(f.facts.reveals_this_field.c, 'unknown');
                assert.equal(f.facts.reveals_this_field.v, null);
            }
        });

        test('Race is recorded as an unverified absence: never as a conditional follow-up', () => {
            const race = byField('race');
            assert.equal(race.status, 'api_only_not_rendered');
            assert.equal(race.facts.present_in_embedded_page_data.c, 'observed');
            assert.equal(race.facts.rendered_as_a_control.v, false);
            assert.equal(race.facts.why_not_rendered.c, 'unknown');
            assert.equal(race.facts.why_not_rendered.v, null);
            assert.match(race.facts.why_not_rendered.src, /UNVERIFIED/);
            assert.ok(!('conditional' in race.facts), 'no conditional claim is made for Race');
            const d = golden.discrepancies.find((x) => x.id === 'race_not_rendered');
            assert.equal(d.explanation_status, 'unknown');
            assert.match(d.explanation, /not asserted to be conditional/);
            assert.ok(golden.unknowns.some((u) => /Race/.test(u) && /not assumed to be conditional/.test(u)));
        });
    });

    describe('every assertion that can be checked against the evidence is', () => {
        const apiFields = new Map();
        api.questions.forEach((q, i) => q.fields.forEach((fl) => apiFields.set(fl.name, { label: q.label, required: q.required, type: fl.type, src: `questions[${i}]`, n: fl.values.length })));
        api.demographic_questions.questions.forEach((q) => apiFields.set(String(q.id), { label: q.label, required: q.required, type: q.type, n: q.answer_options.length }));
        api.compliance.forEach((c) => c.questions.forEach((q) => q.fields.forEach((fl) => apiFields.set(fl.name, { label: q.label, required: q.required, type: fl.type, n: fl.values.length }))));

        test('required and label agree with run #12 for every field that has an observed row', () => {
            for (const f of golden.fields.filter((x) => x.observed?.rows)) {
                const row = rows[f.observed.rows[0]];
                assert.equal(Boolean(row.required), f.facts.required.v, `${f.id}: required`);
                const accepted = [f.facts.label.v, f.facts.dom_accessible_label?.v].filter(Boolean).map(normalise);
                assert.ok(accepted.includes(normalise(row.raw_label)), `${f.id}: row label '${row.raw_label}' is neither the logical nor the DOM label`);
                if (row.external_field_id) assert.ok(f.observed.dom_ids.includes(row.external_field_id), `${f.id}: field id`);
            }
        });

        test('required, label and option counts agree with the API wherever the golden cites it', () => {
            for (const f of golden.fields.filter((x) => x.api)) {
                const a = apiFields.get(f.api.names[0]);
                assert.ok(a, `${f.id}: ${f.api.names[0]} is not in the API capture`);
                assert.equal(f.facts.required.v, a.required, `${f.id}: required vs API`);
                if (f.facts.label.c === 'api_derived' && !['resume_text', 'cover_letter_text'].includes(f.id)) {
                    assert.equal(normalise(f.facts.label.v), normalise(a.label), `${f.id}: label vs API`);
                }
                const count = f.facts.options?.v?.api_count;
                if (typeof count === 'number') assert.equal(count, a.n, `${f.id}: option count vs API`);
            }
        });

        test('widget and type claims agree with the page observations', () => {
            const controls = new Map(statics.controls_by_id.map((c) => [c.id, c]));
            for (const f of golden.fields.filter((x) => x.observed?.dom_ids)) {
                const c = controls.get(f.observed.dom_ids[0]);
                assert.ok(c, `${f.id}: ${f.observed.dom_ids[0]} is not in the page observations`);
                if (f.facts.widget.v === 'combobox') assert.equal(c.role, 'combobox', f.id);
                if (f.facts.kind.v === 'file') assert.equal(c.type, 'file', f.id);
                if (f.facts.kind.v === 'tel') assert.equal(c.type, 'tel', f.id);
                if (f.facts.widget.v === 'native_input') assert.ok(c.role === null, `${f.id} has a role, so it is not a plain input`);
            }
            assert.equal(byField('resume').facts.accepts.v, controls.get('resume').accept);
        });

        test('the file inputs really are labelled "Attach" in the DOM', () => {
            const controls = new Map(statics.controls_by_id.map((c) => [c.id, c]));
            for (const id of ['resume', 'cover_letter']) {
                assert.equal(controls.get(id).label_text, 'Attach');
                assert.equal(controls.get(id).label_visually_hidden, true);
            }
        });
    });

    describe('the differences between the API and the rendered form are computed, so the document cannot drift', () => {
        const apiNames = new Set();
        api.questions.forEach((q) => q.fields.forEach((fl) => apiNames.add(fl.name)));
        api.demographic_questions.questions.forEach((q) => apiNames.add(String(q.id)));
        api.compliance.forEach((c) => c.questions.forEach((q) => q.fields.forEach((fl) => apiNames.add(fl.name))));
        const goldenApiNames = new Set(golden.fields.flatMap((f) => f.api?.names ?? []));
        const observedIds = new Set(rows.map((r) => r.external_field_id).filter(Boolean));

        test('every API field is accounted for by the golden, and the golden names nothing the API lacks', () => {
            assert.deepEqual([...goldenApiNames].sort(), [...apiNames].sort());
        });

        test('API fields with no observed row are exactly the unobserved and not-rendered ones', () => {
            const apiOnly = [...apiNames].filter((n) => !observedIds.has(n)).sort();
            const recorded = golden.fields.filter((f) => ['unobserved', 'api_only_not_rendered'].includes(f.status)).flatMap((f) => f.api.names).sort();
            assert.deepEqual(apiOnly, recorded);
            assert.deepEqual(apiOnly, ['cover_letter_text', 'race', 'resume_text']);
        });

        test('rows that are not in the API are exactly the Country widget and Hispanic/Latino', () => {
            const notInApi = rows.filter((r) => !r.external_field_id || !apiNames.has(r.external_field_id)).map((r) => r.position);
            assert.deepEqual(notInApi, [3, 4, 18]);
            const apiLess = golden.fields.filter((f) => f.api === null).map((f) => f.id).sort();
            assert.deepEqual(apiLess, ['country', 'hispanic_ethnicity']);
            const ids = golden.discrepancies.map((d) => d.id);
            for (const id of ['race_not_rendered', 'hispanic_ethnicity_not_in_api', 'country_widget_not_in_api', 'manual_text_not_rendered']) assert.ok(ids.includes(id), id);
        });

        test('every run #12 row is accounted for exactly once: a field, or a recorded duplicate', () => {
            const primary = golden.fields.flatMap((f) => f.observed?.rows ?? []);
            const duplicates = golden.fields.flatMap((f) => (f.observed?.duplicate_rows ?? []).map((d) => d.row));
            assert.deepEqual([...primary, ...duplicates].sort((a, b) => a - b), rows.map((r) => r.position));
        });

        test('the recorded extraction errors are true of the observed rows', () => {
            const errs = Object.fromEntries(golden.observed_extraction_errors_in_run12.map((e) => [e.id, e]));
            for (const r of errs.dropdowns_typed_text.rows) assert.equal(rows[r].control_type, 'text');
            for (const r of errs.generic_file_labels.rows) assert.equal(rows[r].raw_label, 'Attach');
            for (const r of errs.required_marker_in_label.rows) assert.match(rows[r].raw_label, /\*$/);
            assert.equal(rows[errs.duplicate_row_for_one_control.rows[0]].raw_label, null);
            assert.equal(rows[errs.duplicate_row_for_one_control.rows[0]].label_source, 'unresolved');
            assert.equal(errs.duplicate_row_for_one_control.c, 'inferred');
        });

        test('Race is in the page data but not rendered; the observation does not explain why', () => {
            assert.equal(statics.race.embedded_page_data_contains_question, true);
            assert.equal(statics.race.rendered_label_elements_reading_Race, 0);
            assert.equal(statics.race.rendered_control_with_id_race, false);
            assert.match(statics.race.note, /NOT established/);
            assert.ok(!rows.some((r) => /race\b/i.test(r.raw_label ?? '') && !/racial/i.test(r.raw_label)), 'run #12 extracted no Race question');
        });

        test('the manual text boxes: a hidden label and a button, no textarea', () => {
            assert.equal(statics.document.textarea_elements, 0);
            assert.deepEqual(statics.ids_with_a_label_but_no_control, ['resume_text', 'cover_letter_text']);
            assert.deepEqual(statics.elements_with_id_resume_text_or_cover_letter_text, []);
            assert.ok(statics.manual_entry_buttons.some((b) => b.text === 'Enter manually'));
        });
    });
});

// ---------------------------------------------------------------------------------------------------------------------
describe('expected outputs and the M1 criteria are well formed and consistent', () => {
    const V = corpus.vocabulary;

    test('every fixture has an expected file, a hash and declared coverage', () => {
        for (const f of corpus.fixtures) {
            assert.match(f.sha256, /^[0-9a-f]{64}$/, f.id);
            assert.ok(existsSync(at(f.expected)), `${f.id}: expected file missing`);
            assert.ok(f.covers.length > 0 && f.traits.length > 0, f.id);
            assert.equal(expectedOf(f).fixture, f.id);
        }
        assert.equal(new Set(corpus.fixtures.map((f) => f.id)).size, corpus.fixtures.length);
    });

    test('the corpus covers every adversarial case that was asked for', () => {
        const covered = corpus.fixtures.flatMap((f) => f.covers).join(' | ');
        for (const needle of ['custom comboboxes', 'nested search', 'accessible labels versus nearby headings', 'conditional or hidden', 'sensitive demographic', 'multi-step', 'authentication gates', 'non-GET side effects']) {
            assert.ok(covered.includes(needle), `no fixture covers: ${needle}`);
        }
    });

    test('expected files use only the shared vocabulary and are unambiguous', () => {
        for (const f of corpus.fixtures) {
            const e = expectedOf(f);
            assert.equal(e.schema, 'universal-form-expected/1');
            const ids = [];
            for (const fl of e.m1.fields) {
                assert.ok(V.kind.includes(fl.kind), `${f.id}/${fl.label}: kind ${fl.kind}`);
                assert.equal(typeof fl.required, 'boolean');
                assert.ok(fl.match.dom_ids.length > 0);
                ids.push(...fl.match.dom_ids);
                if (fl.widget) assert.ok(V.widget.includes(fl.widget), `${f.id}/${fl.label}: widget ${fl.widget}`);
                if (fl.label_basis) assert.ok(V.label_basis.includes(fl.label_basis), `${f.id}/${fl.label}: label_basis`);
                if (fl.label_confidence) assert.ok(V.label_confidence.includes(fl.label_confidence));
                if (fl.options) assert.ok(V.options_state.includes(fl.options.state), `${f.id}/${fl.label}: options`);
                if (fl.cardinality) assert.ok(V.cardinality.includes(fl.cardinality));
                if (fl.sensitive !== undefined) assert.equal(typeof fl.sensitive, 'boolean');
            }
            assert.equal(new Set(ids).size, ids.length, `${f.id}: a DOM id is expected twice`);
            for (const o of e.m1.outcome_any_of ?? [e.m1.outcome]) assert.ok(V.outcome.includes(o), `${f.id}: outcome ${o}`);
            for (const m of Object.keys(e.later)) assert.ok(V.milestone.includes(m), `${f.id}: milestone ${m}`);
            assert.deepEqual(Object.keys(e.safety).slice(0, 3), ['clicks', 'non_get_requests', 'dom_mutations']);
            assert.ok(Object.values(e.safety).every((v) => v === 0), `${f.id}: M1 allows no clicks, writes or page changes`);
            assert.ok(!('expected_field_count' in e.m1) && !('field_count' in e.m1), `${f.id} must not pin a field count`);
        }
    });

    test('the golden and the Greenhouse-style replica agree on the shape that matters', () => {
        const replica = expectedOf(corpus.fixtures.find((f) => f.id === 'greenhouse-style-form')).m1.fields;
        const present = golden.fields.filter((f) => f.status === 'present');
        assert.equal(replica.filter((f) => f.sensitive).length, present.filter((f) => f.facts.sensitive.v).length, 'sensitive count');
        assert.equal(replica.filter((f) => f.required).length, present.filter((f) => f.facts.required.v).length, 'required count');
        assert.equal(replica.filter((f) => f.kind === 'select').length, present.filter((f) => f.facts.kind.v === 'select').length, 'select count');
        assert.equal(replica.filter((f) => f.kind === 'file').length, 2);
        assert.equal(replica.length, present.length, 'one expected field per logical field the golden says is present');
    });

    test('sensitive look-alikes are expected NOT to be flagged', () => {
        const e = expectedOf(corpus.fixtures.find((f) => f.id === 'sensitive-demographics')).m1.fields;
        const decoys = e.filter((f) => f.decoy);
        assert.deepEqual(decoys.map((f) => f.match.dom_ids[0]).sort(), ['a11y', 'vets-program', 'work-auth']);
        assert.ok(decoys.every((f) => f.sensitive === false));
        assert.equal(e.filter((f) => f.sensitive).length, 5);
    });

    test('the non-form fixtures expect no fields and say what must not be reported', () => {
        for (const id of ['auth-gate-aggregator', 'apply-button-nonget']) {
            const e = expectedOf(corpus.fixtures.find((f) => f.id === id));
            assert.deepEqual(e.m1.fields, []);
            assert.equal(e.m1.outcome, 'application_not_found');
            assert.ok(e.m1.warnings_include.includes('apply_is_button'));
        }
        const gate = expectedOf(corpus.fixtures.find((f) => f.id === 'auth-gate-aggregator'));
        assert.equal(gate.later.M3.outcome, 'authentication_required');
        assert.ok(gate.later.M3.never.includes('create an account'));
    });

    test('hidden and multi-step content is not asserted as ordinary fields in M1', () => {
        for (const id of ['conditional-hidden-fields', 'multi-step']) {
            const e = expectedOf(corpus.fixtures.find((f) => f.id === id));
            assert.ok(e.m1.must_not_report.length > 0);
            assert.ok(e.later.M2.hidden_in_dom.every((h) => h.visibility === 'hidden_in_dom' && h.milestone === 'M2'));
        }
        const cond = expectedOf(corpus.fixtures.find((f) => f.id === 'conditional-hidden-fields')).later.M2.hidden_in_dom;
        assert.equal(cond.find((h) => h.id === 'visa-type').trigger.relation_class, 'declared');
        assert.equal(cond.find((h) => h.id === 'hear-other').trigger.relation_class, 'unknown', 'a trigger nothing declares is not asserted');
    });

    test('the M1 criteria are measurable, scoped to real fixtures, and do not require 20 fields', () => {
        assert.equal(criteria.milestone, 'M1');
        const ids = new Set(corpus.fixtures.map((f) => f.id));
        for (const id of criteria.applies_to) assert.ok(ids.has(id), `criteria name an unknown fixture ${id}`);
        assert.ok(criteria.not_required.some((t) => /exact field count/.test(t) && /20 rows/.test(t)));
        const metrics = [...criteria.functional, ...criteria.safety, ...criteria.quality];
        assert.equal(new Set(metrics.map((m) => m.id)).size, metrics.length);
        for (const m of metrics) {
            assert.ok(m.name && (m.threshold || m.note), m.id);
            if (m.id.startsWith('M1-F') || m.id.startsWith('M1-S')) assert.ok(m.threshold, `${m.id} has no threshold`);
        }
        for (const id of ['M1-S1', 'M1-S2', 'M1-S3', 'M1-S4', 'M1-S5']) assert.equal(criteria.safety.find((s) => s.id === id).threshold.includes('0') || /identical/.test(criteria.safety.find((s) => s.id === id).threshold), true);
        assert.match(criteria.report_shape, /Any safety failure fails M1/);
    });
});

// ---------------------------------------------------------------------------------------------------------------------
describe('the fixtures are what the corpus says they are: offline, deterministic, read-only to inspect', () => {
    let browser;

    before(async () => {
        browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
    });
    after(async () => {
        await browser?.close();
    });

    const html = (f) => readFileSync(at(f.file), 'utf8');

    test('each fixture is byte-for-byte the one that was reviewed', () => {
        for (const f of corpus.fixtures) assert.equal(sha256(readFileSync(at(f.file))), f.sha256, `${f.id} changed: update corpus.json in review`);
    });

    test('no fixture reaches outside itself, and none runs anything on a timer', () => {
        const { forbidden_in_fixtures: forbidden, external_urls_allowed_hosts: hosts } = corpus.offline_rules;
        for (const f of corpus.fixtures) {
            // Inert data blocks and comments cannot cause a request.
            const live = html(f).replace(/<script[^>]*type="application\/(ld\+)?json"[^>]*>[\s\S]*?<\/script>/g, '').replace(/<!--[\s\S]*?-->/g, '');
            for (const token of forbidden) assert.ok(!live.includes(token), `${f.id} uses ${token}`);
            for (const [, host] of live.matchAll(/https?:\/\/([^/"'\s<>)]+)/g)) assert.ok(hosts.includes(host), `${f.id} refers to ${host}`);
            if (/fetch\(/.test(live)) assert.ok(f.allowed_script_calls?.includes('fetch'), `${f.id} uses fetch without declaring it`);
        }
    });

    test('loading each fixture makes no request at all, and scripts do nothing until the page is used', async () => {
        for (const f of corpus.fixtures) {
            const context = await browser.newContext({ serviceWorkers: 'block' });
            const requests = [];
            await context.route('**/*', (route) => {
                requests.push(`${route.request().method()} ${route.request().url()}`);
                return route.abort();
            });
            const page = await context.newPage();
            await page.setContent(html(f));
            assert.deepEqual(requests, [], `${f.id} made requests while loading`);
            await context.close();
        }
    });

    test('every declared trait holds, so each fixture really exercises what it claims to', async () => {
        const page = await browser.newPage();
        for (const f of corpus.fixtures) {
            await page.setContent(html(f));
            for (const t of f.traits) {
                const where = `${f.id}/${t.id}`;
                if (t.expr !== undefined) {
                    assert.deepEqual(await page.evaluate(t.expr), t.equals, where);
                } else {
                    const n = await page.locator(t.selector).count();
                    if (t.min !== undefined) assert.ok(n >= t.min, `${where}: ${n} < min ${t.min}`);
                    if (t.max !== undefined) assert.ok(n <= t.max, `${where}: ${n} > max ${t.max}`);
                }
            }
        }
        await page.close();
    });

    test('loading is deterministic: the same fixture always produces the same DOM', async () => {
        for (const f of corpus.fixtures) {
            const snapshots = [];
            for (let i = 0; i < 2; i += 1) {
                const page = await browser.newPage();
                await page.setContent(html(f));
                snapshots.push(await page.evaluate(() => document.documentElement.outerHTML));
                await page.close();
            }
            assert.equal(snapshots[0], snapshots[1], f.id);
        }
    });

    test('the traps are armed but untouched: nothing fired, nothing created, nothing revealed', async () => {
        const page = await browser.newPage();
        await page.setContent(html(corpus.fixtures.find((f) => f.id === 'apply-button-nonget')));
        assert.equal(await page.evaluate(() => window.__trap.fired), 0);
        await page.setContent(html(corpus.fixtures.find((f) => f.id === 'multi-step')));
        assert.equal(await page.evaluate(() => document.getElementById('step-3')), null);
        assert.equal(await page.evaluate(() => document.getElementById('step-2').hidden), true);
        await page.setContent(html(corpus.fixtures.find((f) => f.id === 'conditional-hidden-fields')));
        assert.equal(await page.evaluate(() => document.getElementById('province')), null);
        assert.equal(await page.evaluate(() => document.getElementById('visa-details').hidden), true);
        await page.setContent(html(corpus.fixtures.find((f) => f.id === 'auth-gate-aggregator')));
        assert.equal(await page.evaluate(() => document.getElementById('gate').hidden), true);
        await page.close();
    });

    test('the corpus changes nothing outside itself: this suite writes no file and opens no database', () => {
        const self = readFileSync(fileURLToPath(import.meta.url), 'utf8');
        assert.ok(!/writeFileSync|appendFileSync|rmSync|unlinkSync|sqlite|fetch\(|http\.request|https\.request/.test(self.replace(/'[^']*'|"[^"]*"|`[^`]*`|\/\/.*$/gm, '')));
    });
});
