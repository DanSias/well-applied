// Where a case's InspectionResult comes from. All three kinds are results that already exist in the repository's own
// contract shape; the harness never runs an extractor itself.
//   replay        (basis synthetic_fixture_replay) eval/results/<config>/<case>.result.json, produced by the real inspect() over local fixtures (refresh tool,
//                 kept honest by a drift test). Changes with the inspector.
//   snapshot      (basis offline_provider_schema_snapshot) a committed contract result kept in step by the worker's own tests. Changes with the inspector.
//   captured_live (basis captured_live_inspection) a stored production run, re-shaped into the contract. FIXED: it cannot reflect a later inspector.
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { sha256 } from './canon.js';
import { readVerifiedJson } from './evidence.js';

const bool = (v) => (v === null || v === undefined ? null : Boolean(Number(v)));

/** A stored agent_run plus its application_questions rows, as the contract's result. Only these two files are read. */
function capturedRun(verified, root) {
    const run = readVerifiedJson(verified, 'run12_agent_run', root)[0];
    const rows = readVerifiedJson(verified, 'run12_questions', root);
    const summary = JSON.parse(run.result_summary ?? '{}');

    return {
        status: run.status,
        inspection_outcome: run.inspection_outcome,
        stop_reason: run.inspection_stop_reason ?? null,
        ats: run.ats_detected ?? null,
        requested_url: summary.requested_url ?? null,
        final_url: summary.final_url ?? null,
        resolved_url: summary.resolved_url ?? null,
        fields: rows
            .slice()
            .sort((a, b) => a.position - b.position)
            .map((r) => ({ position: r.position, external_field_id: r.external_field_id, raw_label: r.raw_label, label_source: r.label_source, control_type: r.control_type, required: bool(r.required), options: r.options ? JSON.parse(r.options) : null, section: r.section, extraction_source: r.extraction_source })),
        warnings: summary.warnings ?? [],
    };
}

/**
 * @returns {{status:'ok', result:object, basis:string, evidence:object[]} | {status:'absent'|'altered'|'missing', reason:string, basis:string}}
 */
export function loadResult(testCase, config, verified, root, resultsIndex) {
    const spec = testCase.result;
    if (spec.kind === 'captured_live') return { status: 'ok', result: capturedRun(verified, root), basis: 'captured_live_inspection', evidence: [] };
    if (spec.kind === 'snapshot') return { status: 'ok', result: readVerifiedJson(verified, spec.evidence_role, root), basis: 'offline_provider_schema_snapshot', evidence: [] };
    // replay
    const rel = path.join('eval', 'results', config, `${testCase.id}.result.json`);
    const file = path.join(root, rel);
    const expected = resultsIndex?.[config]?.[testCase.id];
    if (!existsSync(file) || !expected) return { status: 'absent', reason: `no replayed result for configuration ${config}`, basis: 'synthetic_fixture_replay' };
    const actual = sha256(readFileSync(file));
    const evidence = [{ role: 'replayed_result', path: rel.split(path.sep).join('/'), class: 'observed', sha256: expected, status: actual === expected ? 'verified' : 'altered', actual_sha256: actual }];
    if (actual !== expected) return { status: 'altered', reason: 'replayed result differs from eval/results/INDEX.json', basis: 'synthetic_fixture_replay', evidence };

    return { status: 'ok', result: JSON.parse(readFileSync(file, 'utf8')), basis: 'synthetic_fixture_replay', evidence };
}
