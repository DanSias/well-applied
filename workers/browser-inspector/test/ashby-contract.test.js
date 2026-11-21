// The Ashby mapping reduced to the EXISTING worker->Laravel contract. Laravel validates and persists the same snapshot:
// tests/Feature/ApplicationInspection/AshbyContractTest.php.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { adaptResponses, toContractResult, AshbyContractError } from '../src/providers/ashby/index.js';
import { LabelSource, ExtractionSource, InspectionOutcome } from '../src/result.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'ashby');
const fx = (n) => JSON.parse(readFileSync(path.join(dir, n), 'utf8'));
const URL_ = 'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application';
const mapped = (job = fx('nerdwallet-job.response.json')) => {
    const r = adaptResponses({ url: URL_, organizationResponse: fx('nerdwallet-org.response.json'), jobPostingResponse: job });
    assert.equal(r.ok, true);
    return r.result;
};
const entries = (j) => j.data.jobPosting.applicationForm.sections[0].fieldEntries;

describe('the Ashby contract snapshot', () => {
    test('is exactly what the mapper produces now (run test/support/ashby-refresh-golden.mjs after an intended change)', () => {
        const c = toContractResult(mapped());
        const snap = fx('expected/nerdwallet-258.contract.result.json');
        delete snap._note;
        assert.deepEqual(c.result, snap);
        assert.deepEqual({ internal: c.internal, coverage: c.coverage }, fx('expected/nerdwallet-258.internal.json'));
    });

    test('uses only the existing keys and enum values, says partial, and claims no URL', () => {
        const { result } = toContractResult(mapped());
        assert.deepEqual(Object.keys(result).sort(), ['ats', 'fields', 'final_url', 'inspection_outcome', 'requested_url', 'resolved_url', 'status', 'stop_reason', 'warnings']);
        assert.equal(result.inspection_outcome, InspectionOutcome.Partial);
        assert.deepEqual([result.requested_url, result.final_url, result.resolved_url, result.stop_reason], [null, null, null, null]);
        for (const f of result.fields) {
            assert.deepEqual(Object.keys(f).sort(), ['control_type', 'external_field_id', 'extraction_source', 'label_source', 'options', 'position', 'raw_label', 'required', 'section']);
            assert.ok(Object.values(LabelSource).includes(f.label_source));
            assert.equal(f.extraction_source, ExtractionSource.ProviderSchema);
        }
    });

    test('keeps identity, labels, types, required, sections and listed options for all 29 provider-reported fields', () => {
        const { fields } = toContractResult(mapped());
        assert.equal(fields.length, 29);
        assert.equal(new Set(fields.map((f) => f.external_field_id)).size, 29);
        assert.deepEqual(fields.map((f) => f.position), [...Array(29).keys()]);
        assert.equal(fields.filter((f) => f.required === true).length, 18);
        assert.equal(fields.filter((f) => f.section === 'NerdWallet Demographic Survey').length, 6);
        assert.ok(fields.filter((f) => f.control_type === 'yes_no').every((f) => f.options === null), 'Yes/No implied by type is not a declared option');
        assert.ok(fields.filter((f) => f.control_type === 'select').some((f) => Array.isArray(f.options) && f.options.length > 1));
    });

    test('keeps visibility, widget, sensitivity, form and conditional wording OUT of the contract but available beside it', () => {
        const c = toContractResult(mapped());
        // The fields carry none of it; the result's warnings only DISCLAIM it ('visibility_not_established').
        const text = JSON.stringify(c.result.fields);
        for (const leaked of ['not_established_from_schema', 'sensitive', 'widget', 'survey_form', 'visibility', 'conditional']) assert.ok(!text.includes(leaked), leaked);
        assert.equal(c.internal.length, 29);
        assert.equal(c.internal.filter((i) => i.sensitive.value).length, 6);
        assert.ok(c.internal.every((i) => i.widget === 'not_established_from_schema' && i.visibility.state === 'not_established_from_schema'));
        assert.equal(c.internal.filter((i) => i.form === 'survey_form').length, 7);
        assert.ok(c.warnings.includes('provider_schema_only') && c.warnings.includes('visibility_not_established'));
    });
});

describe('what changes in the schema does to the contract', () => {
    test('a missing title is unresolved with no label, never an invented one', () => {
        const j = fx('nerdwallet-job.response.json');
        entries(j)[1].field.title = '';
        const f = toContractResult(mapped(j)).fields[1];
        assert.deepEqual([f.raw_label, f.label_source], [null, LabelSource.Unresolved]);
        assert.equal(toContractResult(mapped()).fields.filter((x) => x.label_source === 'unresolved').length, 1, 'the real untitled consent control');
    });

    test('an unknown field type is kept as unknown and warned about', () => {
        const j = fx('nerdwallet-job.response.json');
        entries(j)[2].field.type = 'Hologram';
        const c = toContractResult(mapped(j));
        assert.equal(c.fields[2].control_type, 'unknown');
        assert.ok(c.warnings.includes('unknown_field_type'));
    });

    test('required true, false and undetermined stay distinct', () => {
        const j = fx('nerdwallet-job.response.json');
        entries(j)[0].isRequired = false;
        entries(j)[1].isRequired = null;
        const f = toContractResult(mapped(j)).fields;
        assert.deepEqual([f[0].required, f[1].required], [false, null]);
    });

    test('a changed schema is refused before it reaches the contract', () => {
        const j = fx('nerdwallet-job.response.json');
        j.data.jobPosting.applicationForm.sections = {};
        assert.equal(adaptResponses({ url: URL_, organizationResponse: fx('nerdwallet-org.response.json'), jobPostingResponse: j }).ok, false);
    });

    test('a mapping that is not provider-schema evidence, has no identity, or is out of order is refused', () => {
        const base = () => mapped();
        const cases = {
            not_an_ashby_mapping: (m) => { m.provider = 'greenhouse'; },
            not_provider_schema: (m) => { m.controls[0].provenance.source = 'dom'; },
            missing_field_identity: (m) => { m.controls[0].id = ''; },
            position_mismatch: (m) => { m.controls[3].position = 9; },
        };
        for (const [reason, mutate] of Object.entries(cases)) {
            const m = base();
            mutate(m);
            assert.throws(() => toContractResult(m), (e) => e instanceof AshbyContractError && e.reason === reason, reason);
        }
    });
});
