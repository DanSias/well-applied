// One evaluation run: manifest + configuration -> a deterministic report object. Offline by construction.
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { ratio, sha256 } from './canon.js';
import { verifyEvidence } from './evidence.js';
import { inspectorProvenance } from './provenance.js';
import { loadResult } from './results.js';
import { allUnscored, HIGHER_IS_BETTER, METRICS, scoreCase } from './score.js';
import { buildTruth } from './truth.js';

export const REPORT_SCHEMA = 'eval-report/1';
const SEVERITY = { high: 0, medium: 1, info: 2 };

export function loadManifest(root) {
    const file = path.join(root, 'eval', 'manifest.json');
    const raw = readFileSync(file);

    return { manifest: JSON.parse(raw), manifest_sha256: sha256(raw) };
}

const sumMetric = (records, name) => {
    const rows = records.map((r) => ({ id: r.id, m: r.metrics[name] }));
    const ok = rows.filter((x) => x.m.status === 'scored');
    const numerator = ok.reduce((n, x) => n + x.m.numerator, 0);
    const denominator = ok.reduce((n, x) => n + x.m.denominator, 0);
    const out = { numerator, denominator, value: ratio(numerator, denominator), scorable_cases: ok.length, unscored: rows.filter((x) => x.m.status !== 'scored').map((x) => ({ case: x.id, reason: x.m.reason })) };
    const sec = rows.filter((x) => x.m.secondary_inferred_truth);
    if (sec.length > 0) {
        const n = sec.reduce((a, x) => a + x.m.secondary_inferred_truth.numerator, 0);
        const d = sec.reduce((a, x) => a + x.m.secondary_inferred_truth.denominator, 0);
        out.secondary_inferred_truth = { numerator: n, denominator: d, value: ratio(n, d) };
    }

    return out;
};

function aggregate(records, metricNames) {
    const metrics = Object.fromEntries(metricNames.map((n) => [n, sumMetric(records, n)]));
    const claimsComplete = records.filter((r) => r.false_complete_evaluable);
    const dist = { extraction_source: {}, label_source: {} };
    for (const r of records) for (const k of Object.keys(dist)) for (const [v, n] of Object.entries(r.distribution?.[k] ?? {})) dist[k][v] = (dist[k][v] ?? 0) + n;

    return {
        cases: records.length,
        metrics,
        false_complete_claims: { numerator: claimsComplete.filter((r) => r.false_complete).length, denominator: claimsComplete.length, scorable_cases: claimsComplete.length, case_ids: claimsComplete.filter((r) => r.false_complete).map((r) => r.id) },
        provenance_distribution: dist,
        outcome_confusion: Object.fromEntries(
            [...new Set(records.filter((r) => r.truth_allowed_outcomes).map((r) => r.truth_allowed_outcomes.join('|')))].sort().map((t) => [t, records.filter((r) => r.truth_allowed_outcomes?.join('|') === t).reduce((o, r) => ({ ...o, [r.claimed_outcome]: (o[r.claimed_outcome] ?? 0) + 1 }), {})]),
        ),
    };
}

/** @param {{root:string, config:string, inspectorCommit?:string}} opts */
export function runEvaluation({ root, config, inspectorCommit = null }) {
    const { manifest, manifest_sha256 } = loadManifest(root);
    const indexFile = path.join(root, 'eval', 'results', 'INDEX.json');
    const resultsIndex = existsSync(indexFile) ? JSON.parse(readFileSync(indexFile, 'utf8')) : {};
    const requested = manifest.metrics ?? METRICS;
    const supported = requested.filter((m) => METRICS.includes(m));
    const unsupported = requested.filter((m) => !METRICS.includes(m)).sort();
    const records = [];

    for (const c of [...manifest.cases].sort((a, b) => a.id.localeCompare(b.id))) {
        const verified = verifyEvidence(c.evidence, root);
        const bad = verified.filter((e) => e.status !== 'verified');
        const base = { id: c.id, origin: c.origin, title: c.title, evidence: verified.map(({ role, path: p, class: cl, sha256: h, status }) => ({ role, path: p, class: cl, sha256: h, status })), truth_allowed_outcomes: null, claimed_outcome: null, distribution: null, false_complete: false, false_complete_evaluable: false };
        let outcome;
        let resultInfo = { basis: c.result.kind, status: 'not_loaded' };
        if (bad.length > 0) {
            outcome = { ...allUnscored(bad.some((e) => e.status === 'altered') ? 'evidence_altered' : 'evidence_missing'), findings: bad.map((e) => ({ severity: 'high', code: e.status === 'altered' ? 'evidence_altered' : 'evidence_missing', message: `${e.role} (${e.path}) is ${e.status}` })) };
        } else {
            const res = loadResult(c, config, verified, root, resultsIndex);
            resultInfo = { basis: res.basis, status: res.status, ...(res.reason ? { reason: res.reason } : {}), ...(res.evidence?.length ? { evidence: res.evidence.map(({ role, path: p, class: cl, sha256: h, status }) => ({ role, path: p, class: cl, sha256: h, status })) } : {}) };
            if (res.status !== 'ok') outcome = { ...allUnscored(res.status === 'altered' ? 'evidence_altered' : 'no_result_for_configuration'), findings: res.status === 'altered' ? [{ severity: 'high', code: 'evidence_altered', message: res.reason }] : [] };
            else {
                const truth = buildTruth(c, verified, root);
                outcome = { ...scoreCase(truth, res.result), truth_allowed_outcomes: truth.allowed_outcomes?.v ?? null };
            }
        }
        records.push({ ...base, ...outcome, result: resultInfo, evidence: [...base.evidence, ...(resultInfo.evidence ?? [])], matching: outcome.matching ?? null });
    }

    const groups = {};
    for (const origin of [...new Set(records.map((r) => r.origin))].sort()) groups[origin] = aggregate(records.filter((r) => r.origin === origin), supported);
    const findings = records.flatMap((r) => r.findings.map((f) => ({ case: r.id, origin: r.origin, ...f }))).sort((a, b) => SEVERITY[a.severity] - SEVERITY[b.severity] || a.case.localeCompare(b.case) || a.code.localeCompare(b.code));

    return {
        schema: REPORT_SCHEMA,
        manifest: { schema: manifest.schema, version: manifest.manifest_version, sha256: manifest_sha256 },
        configuration: { name: config, inspector_options: manifest.configurations?.[config] ?? null },
        inspector: inspectorProvenance(root, inspectorCommit),
        metrics_requested: requested,
        unsupported_metrics: unsupported,
        higher_is_better: Object.fromEntries(supported.map((m) => [m, HIGHER_IS_BETTER[m] ?? true])),
        integrity: { verified: records.every((r) => r.evidence.every((e) => e.status === 'verified')), failures: records.flatMap((r) => r.evidence.filter((e) => e.status !== 'verified').map((e) => ({ case: r.id, role: e.role, path: e.path, status: e.status }))) },
        aggregates_by_origin: groups,
        findings,
        cases: records.map(({ distribution, truth_allowed_outcomes, ...r }) => ({ ...r, distribution, truth_allowed_outcomes })),
    };
}
