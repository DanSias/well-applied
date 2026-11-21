// The pure offline scorer: (truth, result) -> per-case metrics and findings. No I/O, no clock, no network, no extractor.
// Every metric is {status:'scored', numerator, denominator, ...} or {status:'unscored', reason}. A missing input is
// "unscored", never a pass and never a failure.
import { matchFields } from './match.js';
import { FIRM, KINDS } from './truth.js';

export const METRICS = ['application_url_resolution', 'platform_classification', 'field_precision', 'field_recall', 'field_f1', 'field_type_accuracy', 'required_accuracy', 'unresolved_label_rate', 'provenance_expectation', 'outcome_classification', 'barrier_classification', 'false_barrier_rate'];
export const HIGHER_IS_BETTER = { unresolved_label_rate: false, false_barrier_rate: false };
export const BARRIERS = new Set(['application_not_found', 'needs_application_url', 'authentication_required', 'mutation_required', 'blocked', 'unsupported']);

const scored = (numerator, denominator, extra = {}) => (denominator > 0 ? { status: 'scored', numerator, denominator, ...extra } : { status: 'unscored', reason: 'no_denominator' });
const unscored = (reason) => ({ status: 'unscored', reason });
const urlKey = (u) => {
    if (u === null || u === undefined) return null;
    try {
        const p = new URL(u);

        return `${p.origin}${p.pathname.replace(/\/+$/, '')}`;
    } catch {
        return String(u);
    }
};

export function allUnscored(reason) {
    return { metrics: Object.fromEntries(METRICS.map((m) => [m, unscored(reason)])), findings: [], matching: null };
}

/** @param {object} truth normalised truth @param {object} result an InspectionResult in the worker->Laravel contract shape */
export function scoreCase(truth, result) {
    const m = {};
    const findings = [];
    const rows = result.status === 'succeeded' ? (result.fields ?? []) : [];
    const claimed = result.status === 'succeeded' ? result.inspection_outcome : 'failed';
    const sources = new Set(rows.map((r) => r.extraction_source));

    // Application URL resolution. A schema-only result carries no URL by design, so it cannot be judged on one.
    if (!truth.application_url) m.application_url_resolution = unscored('no_ground_truth');
    else if (rows.length > 0 && [...sources].every((s) => s === 'provider_schema') && !result.resolved_url) m.application_url_resolution = unscored('schema_only_result_carries_no_url');
    else m.application_url_resolution = scored(urlKey(result.resolved_url) === urlKey(truth.application_url.v) ? 1 : 0, 1);

    // Platform. A result with no ATS is "unknown".
    m.platform_classification = !truth.platform ? unscored('no_ground_truth') : scored((result.ats ?? 'unknown') === truth.platform.v ? 1 : 0, 1);

    // Field matching.
    const scorable = truth.fields.filter((f) => f.scorable);
    const excluded = truth.fields.filter((f) => !f.scorable).map((f) => ({ key: f.key, reason: f.reason }));
    const match = matchFields(scorable, rows);
    const tp = match.matched.length;
    const fp = match.extra.length + match.duplicates.length;
    const fn = match.missed.length;
    const detail = { true_positives: tp, false_positives: fp, false_negatives: fn, ambiguous_rows: match.ambiguous.length, ambiguous_truth_fields: match.ambiguous_truth.length, non_scorable_truth_fields: excluded.length };
    const exhaustive = truth.exhaustive?.v === true;
    m.field_recall = scorable.length === 0 ? unscored('no_scorable_truth_fields') : scored(tp, tp + fn, { detail });
    m.field_precision = !exhaustive ? unscored('truth_not_exhaustive') : scored(tp, tp + fp, { detail });
    m.field_f1 = m.field_recall.status === 'scored' && m.field_precision.status === 'scored' ? scored(2 * tp, 2 * tp + fp + fn, { detail }) : unscored(m.field_recall.status !== 'scored' ? m.field_recall.reason : m.field_precision.reason);

    // Type and required accuracy, over matched pairs only, split by the class of the TRUTH fact.
    const byKey = new Map(scorable.map((f) => [f.key, f]));
    const attr = (name, equal, unmappable) => {
        let num = 0;
        let den = 0;
        const inferred = { numerator: 0, denominator: 0 };
        const skipped = { unknown_truth: 0, abstained: 0, unmapped: 0 };
        for (const { truth: key, row } of match.matched) {
            const t = byKey.get(key)[name];
            const r = rows[row];
            if (!t || t.c === 'unknown') {
                skipped.unknown_truth++;
                continue;
            }
            if (unmappable(t.v, r)) {
                skipped.unmapped++;
                continue;
            }
            if (r[name === 'kind' ? 'control_type' : 'required'] === null || r[name === 'kind' ? 'control_type' : 'required'] === undefined) {
                skipped.abstained++;
                continue;
            }
            const ok = equal(t.v, r) ? 1 : 0;
            if (FIRM.has(t.c)) {
                num += ok;
                den++;
            } else {
                inferred.numerator += ok;
                inferred.denominator++;
            }
        }
        if (den === 0 && inferred.denominator === 0) return unscored(match.matched.length === 0 ? 'no_matched_fields' : 'no_comparable_truth');
        const out = scored(num, den, { secondary_inferred_truth: inferred, skipped });

        return den === 0 ? { ...unscored('only_inferred_truth'), secondary_inferred_truth: inferred, skipped } : out;
    };
    m.field_type_accuracy = attr('kind', (v, r) => r.control_type === v, (v, r) => !KINDS.has(v) || !KINDS.has(r.control_type));
    m.required_accuracy = attr('required', (v, r) => r.required === v, () => false);

    // Unresolved labels: result-intrinsic, needs no truth.
    const unresolvedRows = rows.filter((r) => r.label_source === 'unresolved' || !r.raw_label || r.raw_label.trim() === '').length;
    m.unresolved_label_rate = rows.length === 0 ? unscored('no_rows') : scored(unresolvedRows, rows.length);

    // Provenance: is every row from a source the truth allows?
    m.provenance_expectation = !truth.provenance ? unscored('no_ground_truth') : rows.length === 0 ? unscored('no_rows') : scored(rows.filter((r) => truth.provenance.allowed.includes(r.extraction_source)).length, rows.length);

    // Outcome and barrier classification.
    const allowed = truth.allowed_outcomes?.v ?? null;
    const truthIsBarrier = allowed ? allowed.every((o) => BARRIERS.has(o)) : null;
    m.outcome_classification = !allowed ? unscored('no_ground_truth') : scored(allowed.includes(claimed) ? 1 : 0, 1);
    if (!allowed) {
        m.barrier_classification = unscored('no_ground_truth');
        m.false_barrier_rate = unscored('no_ground_truth');
    } else if (truthIsBarrier) {
        const stopOk = truth.stop_reason?.v === undefined || truth.stop_reason.v === null || (result.stop_reason ?? null) === truth.stop_reason.v;
        m.barrier_classification = scored(allowed.includes(claimed) && stopOk ? 1 : 0, 1);
        m.false_barrier_rate = unscored('truth_is_a_barrier');
    } else {
        m.barrier_classification = unscored('truth_is_not_a_barrier');
        m.false_barrier_rate = scored(BARRIERS.has(claimed) ? 1 : 0, 1);
    }

    // Findings: claimed versus supported completeness. A false claim of complete coverage is high severity.
    const warnings = result.warnings ?? [];
    if (claimed === 'complete') {
        if (allowed && !allowed.includes('complete')) findings.push({ severity: 'high', code: 'false_complete', message: `claimed complete, but the evidence supports only ${allowed.join(' or ')}` });
        else if (exhaustive && match.missed.length > 0) findings.push({ severity: 'high', code: 'false_complete', message: `claimed complete with ${match.missed.length} known field(s) missing: ${match.missed.join(', ')}` });
        else if (!allowed) findings.push({ severity: 'medium', code: 'unverified_complete', message: 'claimed complete; the ground truth does not establish whether the form is complete' });
    }
    if (sources.has('provider_schema')) {
        const missing = ['provider_schema_only', 'visibility_not_established'].filter((w) => !warnings.includes(w));
        if (claimed === 'complete' || missing.length > 0) findings.push({ severity: 'high', code: 'provider_schema_overclaim', message: claimed === 'complete' ? 'provider-schema fields claimed complete coverage' : `provider-schema fields lack the warning(s): ${missing.join(', ')}` });
    }
    if (match.ambiguous.length + match.ambiguous_truth.length > 0) findings.push({ severity: 'info', code: 'ambiguous_matches_excluded', message: `${match.ambiguous.length} row(s) and ${match.ambiguous_truth.length} truth field(s) could not be matched unambiguously and are excluded from the counts` });

    const distribution = { extraction_source: {}, label_source: {} };
    for (const r of rows) {
        distribution.extraction_source[r.extraction_source] = (distribution.extraction_source[r.extraction_source] ?? 0) + 1;
        distribution.label_source[r.label_source] = (distribution.label_source[r.label_source] ?? 0) + 1;
    }
    const falseCompleteEvaluable = claimed === 'complete' && (Boolean(allowed) || exhaustive);

    return {
        metrics: m,
        findings,
        claimed_outcome: claimed,
        false_complete_evaluable: falseCompleteEvaluable,
        false_complete: findings.some((f) => f.code === 'false_complete'),
        distribution,
        matching: { matched: match.matched.map((x) => ({ truth: x.truth, row: x.row, by: x.by })), missed: match.missed, extra_rows: match.extra, duplicate_rows: match.duplicates.map((d) => d.row), ambiguous: match.ambiguous, ambiguous_truth: match.ambiguous_truth, excluded_truth_fields: excluded },
        truth_classes: { exhaustive: truth.exhaustive?.c ?? null },
    };
}
