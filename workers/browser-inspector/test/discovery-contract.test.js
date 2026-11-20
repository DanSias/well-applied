// The committed contract snapshots are what the worker really produces today (drift test), and they use only the existing
// result contract. Laravel validates and persists the same files: tests/Feature/ApplicationInspection/GenericDiscoveryContractTest.php.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildSnapshots, SNAPSHOTS } from './support/contract-snapshots.js';
import { LabelSource, ExtractionSource, InspectionOutcome, StopReason } from '../src/result.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'universal', 'contract');
const committed = (name) => JSON.parse(readFileSync(path.join(dir, `${name}.result.json`), 'utf8'));

describe('generic discovery results, against the committed contract snapshots', () => {
    test('the snapshots are exactly what the worker produces now (run test/support/refresh-contract-snapshots.mjs after an intended change)', async () => {
        const live = await buildSnapshots();
        for (const s of SNAPSHOTS) assert.deepEqual(live[s.name], committed(s.name), s.name);
    });

    test('they use only the existing contract: top-level keys, field keys, enums, outcome and stop reason', () => {
        for (const s of SNAPSHOTS) {
            const r = committed(s.name);
            assert.deepEqual(Object.keys(r).filter((k) => k !== '_note').sort(), ['ats', 'diagnostics', 'fields', 'final_url', 'inspection_outcome', 'requested_url', 'resolved_url', 'status', 'stop_reason', 'warnings']);
            assert.ok(Object.values(InspectionOutcome).includes(r.inspection_outcome));
            assert.ok(r.stop_reason === null || Object.values(StopReason).includes(r.stop_reason));
            for (const f of r.fields) {
                assert.deepEqual(Object.keys(f).sort(), ['control_type', 'external_field_id', 'extraction_source', 'label_source', 'options', 'position', 'raw_label', 'required', 'section']);
                assert.ok(Object.values(LabelSource).includes(f.label_source), f.label_source);
                assert.ok(Object.values(ExtractionSource).includes(f.extraction_source));
            }
        }
    });

    test('and they say what they should: unknown-ATS complete, the other partial because content was seen and not read', () => {
        assert.deepEqual([committed('generic-unknown-ats-form').inspection_outcome, committed('generic-unknown-ats-form').fields.length], ['complete', 6]);
        const p = committed('generic-greenhouse-style-partial');
        assert.deepEqual([p.inspection_outcome, p.stop_reason, p.warnings.includes('conditional_fields_not_inspected'), p.warnings.includes('generic_discovery')], ['partial', null, true, true]);
    });
});
