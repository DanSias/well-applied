// The offline Ashby adapter's response side: validation, mapping, and the comparison with what a real browser showed.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { adaptResponses, validateJobPostingResponse, validateOrganizationResponse, mapAshbyForm } from '../src/providers/ashby/index.js';
import { LIMITS } from '../src/providers/ashby/response.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'ashby');
const fx = (n) => JSON.parse(readFileSync(path.join(dir, n), 'utf8'));
const URL_ = 'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application';
const ID = '32c62385-07d5-4d6d-960e-12c05ce0cbef';
const job = () => fx('nerdwallet-job.response.json');
const org = () => fx('nerdwallet-org.response.json');
const adapt = (jobPostingResponse = job(), organizationResponse = org()) => adaptResponses({ url: URL_, organizationResponse, jobPostingResponse });
const firstEntry = (j) => j.data.jobPosting.applicationForm.sections[0].fieldEntries[0];
const reasonOf = (r) => `${r.stage}/${r.reason}`;

describe('refusing a response that is not the schema the mapper understands', () => {
    test('the captured responses are accepted', () => assert.equal(adapt().ok, true));

    const cases = {
        'job_posting/not_an_object': () => [null, org()],
        'job_posting/graphql_errors': () => [{ errors: [{ message: 'boom' }], data: null }, org()],
        'job_posting/missing_data': () => [{}, org()],
        'job_posting/job_not_found': () => [{ data: { jobPosting: null } }, org()],
        'job_posting/job_id_mismatch': () => { const j = job(); j.data.jobPosting.id = '00000000-0000-4000-8000-000000000000'; return [j, org()]; },
        'job_posting/missing_application_form': () => { const j = job(); delete j.data.jobPosting.applicationForm; return [j, org()]; },
        'job_posting/schema_changed': () => { const j = job(); j.data.jobPosting.applicationForm.sections = 'nope'; return [j, org()]; },
        'organization/organization_mismatch': () => { const o = org(); o.data.organization.hostedJobsPageSlug = 'someone-else'; return [job(), o]; },
        'organization/organization_not_found': () => [job(), { data: { organization: null } }],
        'organization/schema_changed': () => { const o = org(); delete o.data.organization.name; return [job(), o]; },
    };
    for (const [expected, make] of Object.entries(cases)) {
        test(`${expected}`, () => {
            const r = adapt(...make());
            assert.equal(r.ok, false);
            assert.equal(reasonOf(r), expected);
        });
    }

    test('a field that lost its path, type, or got a wrongly typed flag names the exact place', () => {
        for (const [mutate, where] of [
            [(e) => delete e.field.path, 'fieldEntries[0].field.path'],
            [(e) => { e.field.type = 7; }, 'fieldEntries[0].field.type'],
            [(e) => { e.isRequired = 'yes'; }, 'fieldEntries[0].isRequired'],
            [(e) => { e.field.selectableValues = 'x'; }, 'selectableValues'],
            [(e) => { e.field.selectableValues = [{ label: 1, value: 'a' }]; }, 'selectableValues[0]'],
            [(e) => { e.field.title = { text: 'x' }; }, 'field.title'],
        ]) {
            const j = job();
            mutate(firstEntry(j));
            const r = validateJobPostingResponse(j.data ? j : null, { jobPostingId: ID });
            assert.equal(r.ok, false, where);
            assert.equal(r.reason, 'schema_changed', where);
            assert.match(r.detail, new RegExp(where.replace(/[[\]]/g, '\\$&')), where);
        }
    });

    test('bounds: duplicate paths, too many fields, options or sections, over-long text', () => {
        let j = job();
        j.data.jobPosting.applicationForm.sections[0].fieldEntries[1].field.path = firstEntry(j).field.path;
        assert.equal(validateJobPostingResponse(j, {}).reason, 'duplicate_field_path');

        j = job();
        const e = firstEntry(j);
        j.data.jobPosting.applicationForm.sections[0].fieldEntries = Array.from({ length: LIMITS.fields + 1 }, (_, i) => ({ ...e, field: { ...e.field, path: `p${i}` } }));
        assert.equal(validateJobPostingResponse(j, {}).reason, 'too_many_fields');

        j = job();
        j.data.jobPosting.applicationForm.sections[0].fieldEntries[0].field.selectableValues = Array.from({ length: LIMITS.options + 1 }, (_, i) => ({ label: `o${i}`, value: `${i}` }));
        assert.equal(validateJobPostingResponse(j, {}).reason, 'too_many_options');

        j = job();
        j.data.jobPosting.applicationForm.sections = Array.from({ length: LIMITS.sections + 1 }, () => ({ title: 't', fieldEntries: [] }));
        assert.equal(validateJobPostingResponse(j, {}).reason, 'too_many_sections');

        j = job();
        firstEntry(j).field.title = 'x'.repeat(LIMITS.text + 1);
        assert.equal(validateJobPostingResponse(j, {}).reason, 'string_too_long');
    });

    test('tolerates additions: unknown keys are ignored, and an unseen field type is kept and flagged, not refused', () => {
        const j = job();
        j.data.jobPosting.somethingNew = { a: 1 };
        firstEntry(j).field.type = 'Date';
        firstEntry(j).extra = true;
        const r = adapt(j);
        assert.equal(r.ok, true);
        assert.equal(r.result.controls[0].kind, 'unknown');
        assert.equal(r.result.controls[0].provenance.schema_type, 'Date');
        assert.ok(r.result.warnings.includes('unknown_field_type'));
        assert.equal(r.result.coverage.unknown_types, 1);
    });

    test('never carries a value a candidate entered: fieldValue is dropped', () => {
        const j = job();
        firstEntry(j).fieldValue = { value: 'Jane Doe' };
        assert.equal(JSON.stringify(adapt(j).result).includes('Jane Doe'), false);
    });

    test('a hidden field or section is reported as hidden by the schema, never as visible', () => {
        const j = job();
        firstEntry(j).isHidden = true;
        const r = adapt(j).result;
        assert.equal(r.controls[0].visibility.state, 'hidden_by_schema');
        assert.equal(r.coverage.hidden_by_schema, 1);
        assert.ok(r.controls.every((c) => c.visibility.state !== 'visible'));
    });

    test('an organisation response that is an array, or errors', () => {
        assert.equal(validateOrganizationResponse([], {}).reason, 'not_an_object');
        assert.equal(validateOrganizationResponse({ errors: [{ message: 'x' }] }, {}).reason, 'graphql_errors');
    });
});

describe('the NerdWallet job (job 258, run 13): what the schema establishes', () => {
    const { result } = adapt();
    const c = result.coverage;

    test('keeps the 22 application fields and 7 survey fields apart, and assumes neither is all visible or all required', () => {
        assert.equal(c.application_form_fields, 22);
        assert.equal(c.survey_fields, 7);
        assert.equal(c.total, 29);
        assert.equal(c.required_application_form, 18);
        assert.equal(c.required_survey_form, 0);
        assert.deepEqual([...new Set(result.controls.filter((x) => x.form === 'survey_form').map((x) => x.required.value))], [false]);
        assert.ok(result.controls.every((x) => x.visibility.state === 'not_established_from_schema'));
        assert.ok(result.controls.every((x) => x.widget === 'not_established_from_schema'));
        assert.ok(result.controls.every((x) => x.provenance.source === 'provider_schema' && x.label.source === 'provider_schema'));
    });

    test('types, sections and sensitivity', () => {
        assert.deepEqual(c.by_type, { Boolean: 7, Email: 1, File: 2, LongText: 2, MultiValueSelect: 2, Phone: 1, String: 8, ValueSelect: 6 });
        assert.deepEqual(c.sections.filter((s) => s.form === 'application_form').map((s) => [s.title, s.fields]), [['Personal Information', 7], ['Links', 2], ['Role-Specific Questions', 4], ['Questions', 9]]);
        const sensitive = result.controls.filter((x) => x.sensitive.value);
        assert.equal(sensitive.length, 6);
        assert.ok(sensitive.every((x) => x.form === 'survey_form' && x.section === 'NerdWallet Demographic Survey'));
        assert.equal(result.controls.filter((x) => x.form === 'application_form' && x.sensitive.value).length, 0);
    });

    test('the untitled consent checkbox is unlabelled, not given an invented label', () => {
        const u = result.controls.filter((x) => x.label.text === null);
        assert.equal(u.length, 1);
        assert.deepEqual(u[0].options.labels, ['I agree']);
        assert.ok(result.warnings.includes('unlabelled_control'));
    });

    test('Yes/No options are marked implied by the type, not read from the schema', () => {
        const b = result.controls.filter((x) => x.provenance.schema_type === 'Boolean');
        assert.equal(b.length, 7);
        assert.ok(b.every((x) => x.options.state === 'implied_by_type' && x.kind === 'yes_no'));
    });

    test('conditional "If ..." questions are hints from wording only', () => {
        const hints = result.controls.filter((x) => x.conditional_hint !== null).map((x) => x.label.text.slice(0, 20));
        assert.deepEqual(hints, ['If other, please spe', 'If yes, please speci']);
        assert.ok(result.warnings.includes('conditional_logic_not_in_schema'));
    });

    test('says what it cannot establish, and page features are offered-not-rendered', () => {
        assert.deepEqual(c.not_established, ['visibility_on_page', 'collapsed_or_expanded', 'conditional_rules', 'widget_used', 'render_order_on_page', 'submit_time_validation']);
        assert.deepEqual(result.page_features, { recaptcha_declared: { action: 'job_apply_none' }, autofill_from_resume_offered: true, application_limit_notice: true });
    });

    test('matches the committed golden exactly', () => {
        assert.deepEqual(result, fx('expected/nerdwallet-258.controls.json'));
    });
});

describe('compared with what a real Chrome showed on the same page', () => {
    const dom = fx('dom-observation.json');
    const { result } = adapt();
    const app = result.controls.filter((x) => x.form === 'application_form');
    const same = (schemaLabel, domLabel) => schemaLabel.replace(/\s+/g, ' ').startsWith(domLabel.trim()) || domLabel.trim().startsWith(schemaLabel.replace(/\s+/g, ' ').slice(0, 90));

    test('every non-Yes/No application field is matched by a visible DOM control, by label except one matched by type only', () => {
        const matched = [];
        for (const f of app.filter((x) => x.kind !== 'yes_no')) {
            const byLabel = dom.visible_controls.find((d) => d.label && same(f.label.text, d.label));
            if (byLabel) {
                matched.push([f.label.text, 'label']);
                assert.equal(byLabel.required === true, f.required.value === true, `required: ${f.label.text}`);
            } else matched.push([f.label.text, 'type_only']);
        }
        assert.equal(matched.length, 15);
        assert.deepEqual(matched.filter(([, how]) => how === 'type_only').map(([l]) => l), ['How did you hear about this job?']);
    });

    test('the seven Boolean questions equal the seven Yes/No button pairs, by count only (the DOM gave no labels)', () => {
        assert.equal(app.filter((x) => x.kind === 'yes_no').length, dom.yes_no_button_pairs);
    });

    test('every survey select has the same options as the collapsed DOM groups, in order', () => {
        const survey = result.controls.filter((x) => x.form === 'survey_form').map((x) => x.options.labels.map((l) => l.replace(/\s+/g, ' ')));
        assert.equal(survey.length, dom.collapsed_survey_groups.length);
        survey.forEach((labels, i) => {
            const d = dom.collapsed_survey_groups[i].options;
            assert.equal(labels.length, d.length, `group ${i}`);
            labels.forEach((l, k) => assert.ok(l.startsWith(d[k]) || d[k].startsWith(l.slice(0, 90)), `group ${i} option ${k}: ${l}`));
        });
    });

    test('what only the DOM shows is NOT claimed by the schema', () => {
        const claimed = JSON.stringify(result);
        for (const fact of ['Submit Application', 'iframe', 'Overview']) assert.equal(claimed.includes(fact), false, fact);
        assert.equal(dom.document_forms, 0);
        assert.ok(dom.page_facts_not_in_a_field_schema.length >= 4);
    });
});
