// Stage 0 evaluation harness: the pure scorer and the matcher. No files, no browser, no network.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { scoreCase } from '../eval/src/score.js';
import { matchFields } from '../eval/src/match.js';

const tf = (key, label, extra = {}) => ({ key, ids: [key], label, label_truncated: false, exists: 'authored', kind: { v: 'text', c: 'authored' }, required: { v: true, c: 'authored' }, scorable: true, ...extra });
const row = (id, label, extra = {}) => ({ external_field_id: id, raw_label: label, label_source: 'dom_label_for', control_type: 'text', required: true, options: null, section: null, extraction_source: 'dom', ...extra });
const truth = (fields, extra = {}) => ({ exhaustive: { v: true, c: 'authored' }, fields, platform: null, application_url: null, allowed_outcomes: null, stop_reason: null, provenance: null, ...extra });
const result = (fields, extra = {}) => ({ status: 'succeeded', inspection_outcome: 'partial', stop_reason: null, ats: null, resolved_url: null, warnings: [], fields, ...extra });

describe('field matching', () => {
    test('matches by id first, then by label, one-to-one', () => {
        const m = matchFields([tf('a', 'First name'), tf('b', 'Email')], [row('zzz', 'EMAIL *'), row('a', 'whatever'), row('q', 'extra')]);
        assert.deepEqual(m.matched.map((x) => [x.truth, x.row, x.by]).sort(), [['a', 1, 'id'], ['b', 0, 'label']]);
        assert.deepEqual(m.extra, [2]);
        assert.deepEqual(m.missed, []);
    });

    test('an ambiguous label match is reported as ambiguous, not guessed', () => {
        const m = matchFields([tf('a', 'Website', { ids: [] }), tf('b', 'Website', { ids: [] })], [row(null, 'Website')]);
        assert.equal(m.matched.length, 0);
        assert.equal(m.ambiguous.length, 1);
        assert.deepEqual(m.ambiguous_truth, ['a', 'b']);
        assert.deepEqual(m.missed, [], 'ambiguous truth is excluded, not counted as missed');
        assert.deepEqual(m.extra, [], 'an ambiguous row is excluded, not counted as spurious');
    });

    test('two rows that both claim one truth field: the first matches, the second is a duplicate', () => {
        const m = matchFields([tf('country', 'Country')], [row('country', 'Country'), row('country', 'Country')]);
        assert.deepEqual([m.matched.length, m.duplicates.length], [1, 1]);
    });

    test('a truncated reference label matches by prefix, but only when it is long enough to identify the question', () => {
        const long = 'Describe one automation you personally built that used an LLM';
        const ok = matchFields([tf('x', long, { ids: [], label_truncated: true })], [row(null, `${long} or generative AI. What problem did it solve?`)]);
        assert.equal(ok.matched.length, 1);
        const short = matchFields([tf('x', 'Describe', { ids: [], label_truncated: true })], [row(null, 'Describe your experience')]);
        assert.equal(short.matched.length, 0);
        assert.equal(short.missed.length, 1);
    });

    test('a row with no id and no label can never match', () => {
        const m = matchFields([tf('a', 'Name')], [row(null, null)]);
        assert.deepEqual([m.matched.length, m.extra, m.missed], [0, [0], ['a']]);
    });
});

describe('metrics carry numerator, denominator and honest scorability', () => {
    test('a perfect result scores 1 on precision, recall and F1', () => {
        const s = scoreCase(truth([tf('a', 'A'), tf('b', 'B')]), result([row('a', 'A'), row('b', 'B')]));
        for (const n of ['field_precision', 'field_recall']) assert.deepEqual([s.metrics[n].status, s.metrics[n].numerator, s.metrics[n].denominator], ['scored', 2, 2]);
        assert.deepEqual([s.metrics.field_f1.numerator, s.metrics.field_f1.denominator], [4, 4]);
    });

    test('a missing field lowers recall, an extra row lowers precision', () => {
        const s = scoreCase(truth([tf('a', 'A'), tf('b', 'B')]), result([row('a', 'A'), row('junk', 'Junk')]));
        assert.deepEqual([s.metrics.field_recall.numerator, s.metrics.field_recall.denominator], [1, 2]);
        assert.deepEqual([s.metrics.field_precision.numerator, s.metrics.field_precision.denominator], [1, 2]);
    });

    test('precision is unscored when the truth is not exhaustive: extra rows cannot be called wrong', () => {
        const s = scoreCase(truth([tf('a', 'A')], { exhaustive: { v: false, c: 'observed' } }), result([row('a', 'A'), row('more', 'More')]));
        assert.equal(s.metrics.field_precision.status, 'unscored');
        assert.equal(s.metrics.field_precision.reason, 'truth_not_exhaustive');
        assert.equal(s.metrics.field_recall.status, 'scored');
    });

    test('missing ground truth is unscored, not a pass and not a failure', () => {
        const s = scoreCase(truth([]), result([row('a', 'A')]));
        for (const n of ['application_url_resolution', 'platform_classification', 'outcome_classification', 'barrier_classification', 'provenance_expectation', 'field_recall']) assert.equal(s.metrics[n].status, 'unscored', n);
        assert.equal(s.metrics.application_url_resolution.reason, 'no_ground_truth');
    });

    test('an ambiguous match is excluded from the counts and flagged', () => {
        const s = scoreCase(truth([tf('a', 'Website', { ids: [] }), tf('b', 'Website', { ids: [] }), tf('c', 'Email')]), result([row(null, 'Website'), row('c', 'Email')]));
        assert.deepEqual([s.metrics.field_recall.numerator, s.metrics.field_recall.denominator], [1, 1]);
        assert.ok(s.findings.some((f) => f.code === 'ambiguous_matches_excluded'));
    });

    test('type accuracy counts only firm truth; inferred truth is reported separately and unknown truth not at all', () => {
        const fields = [tf('a', 'A', { kind: { v: 'select', c: 'inferred' } }), tf('b', 'B', { kind: { v: 'select', c: 'unknown' } }), tf('c', 'C', { kind: { v: 'tel', c: 'observed' } })];
        const s = scoreCase(truth(fields), result([row('a', 'A'), row('b', 'B'), row('c', 'C', { control_type: 'tel' })]));
        assert.deepEqual([s.metrics.field_type_accuracy.numerator, s.metrics.field_type_accuracy.denominator], [1, 1]);
        assert.deepEqual(s.metrics.field_type_accuracy.secondary_inferred_truth, { numerator: 0, denominator: 1 });
        assert.equal(s.metrics.field_type_accuracy.skipped.unknown_truth, 1);
    });

    test('a type outside the shared vocabulary is skipped, not marked wrong', () => {
        const s = scoreCase(truth([tf('a', 'A', { kind: { v: 'combobox', c: 'observed' } })]), result([row('a', 'A')]));
        assert.equal(s.metrics.field_type_accuracy.status, 'unscored');
    });

    test('a required flag the inspector declined to state is an abstention, not an error', () => {
        const s = scoreCase(truth([tf('a', 'A'), tf('b', 'B')]), result([row('a', 'A', { required: null }), row('b', 'B', { required: false })]));
        assert.deepEqual([s.metrics.required_accuracy.numerator, s.metrics.required_accuracy.denominator, s.metrics.required_accuracy.skipped.abstained], [0, 1, 1]);
    });

    test('the unresolved-label rate needs no truth', () => {
        const s = scoreCase(truth([]), result([row('a', 'A'), row(null, null, { label_source: 'unresolved' }), row('c', '  ')]));
        assert.deepEqual([s.metrics.unresolved_label_rate.numerator, s.metrics.unresolved_label_rate.denominator], [2, 3]);
    });

    test('URL resolution compares origin and path only, and a schema-only result is not judged on a URL it never carries', () => {
        const t = truth([], { application_url: { v: 'https://x.example/a/b/', c: 'observed' } });
        assert.equal(scoreCase(t, result([], { resolved_url: 'https://x.example/a/b?utm=1#f' })).metrics.application_url_resolution.numerator, 1);
        assert.equal(scoreCase(t, result([], { resolved_url: null })).metrics.application_url_resolution.numerator, 0);
        const schemaOnly = scoreCase(t, result([row(null, 'A', { extraction_source: 'provider_schema' })], { warnings: ['provider_schema_only', 'visibility_not_established'] }));
        assert.equal(schemaOnly.metrics.application_url_resolution.reason, 'schema_only_result_carries_no_url');
    });

    test('platform: no ATS means "unknown"', () => {
        const t = truth([], { platform: { v: 'unknown', c: 'authored' } });
        assert.equal(scoreCase(t, result([], { ats: null })).metrics.platform_classification.numerator, 1);
        assert.equal(scoreCase(t, result([], { ats: 'greenhouse' })).metrics.platform_classification.numerator, 0);
    });

    test('barrier classification checks outcome and stop reason; a false barrier is counted on extractable pages', () => {
        const barrier = truth([], { allowed_outcomes: { v: ['application_not_found'], c: 'authored' }, stop_reason: { v: 'no_apply_link', c: 'authored' } });
        assert.equal(scoreCase(barrier, result([], { inspection_outcome: 'application_not_found', stop_reason: 'no_apply_link' })).metrics.barrier_classification.numerator, 1);
        assert.equal(scoreCase(barrier, result([], { inspection_outcome: 'application_not_found', stop_reason: 'other' })).metrics.barrier_classification.numerator, 0);
        assert.equal(scoreCase(barrier, result([], { inspection_outcome: 'complete' })).metrics.barrier_classification.numerator, 0);
        const form = truth([], { allowed_outcomes: { v: ['complete', 'partial'], c: 'authored' } });
        assert.equal(scoreCase(form, result([], { inspection_outcome: 'unsupported' })).metrics.false_barrier_rate.numerator, 1);
        assert.equal(scoreCase(form, result([], { inspection_outcome: 'partial' })).metrics.false_barrier_rate.numerator, 0);
    });

    test('a failed run found nothing: recall is scored as zero, never skipped', () => {
        const s = scoreCase(truth([tf('a', 'A')]), { status: 'failed', fields: [], warnings: [] });
        assert.deepEqual([s.metrics.field_recall.numerator, s.metrics.field_recall.denominator, s.claimed_outcome], [0, 1, 'failed']);
    });
});

describe('claimed completeness versus supported completeness', () => {
    test('complete where the evidence allows only partial is a high-severity false claim', () => {
        const t = truth([tf('a', 'A')], { allowed_outcomes: { v: ['partial'], c: 'authored' } });
        const s = scoreCase(t, result([row('a', 'A')], { inspection_outcome: 'complete' }));
        assert.deepEqual(s.findings.filter((f) => f.severity === 'high').map((f) => f.code), ['false_complete']);
        assert.equal(s.false_complete, true);
    });

    test('complete with a known field missing is a high-severity false claim, even when the outcome list would allow it', () => {
        const t = truth([tf('a', 'A'), tf('b', 'B')], { allowed_outcomes: { v: ['complete', 'partial'], c: 'authored' } });
        const s = scoreCase(t, result([row('a', 'A')], { inspection_outcome: 'complete' }));
        assert.equal(s.findings.find((f) => f.code === 'false_complete').severity, 'high');
        assert.match(s.findings[0].message, /b/);
    });

    test('a justified complete is not a finding, and is counted as evaluable', () => {
        const t = truth([tf('a', 'A')], { allowed_outcomes: { v: ['complete'], c: 'authored' } });
        const s = scoreCase(t, result([row('a', 'A')], { inspection_outcome: 'complete' }));
        assert.deepEqual(s.findings, []);
        assert.deepEqual([s.false_complete_evaluable, s.false_complete], [true, false]);
    });

    test('complete where the truth establishes nothing is an unverified claim, reported but not high severity', () => {
        const s = scoreCase(truth([tf('a', 'A')], { exhaustive: { v: false, c: 'observed' } }), result([row('a', 'A')], { inspection_outcome: 'complete' }));
        assert.deepEqual(s.findings.map((f) => [f.code, f.severity]), [['unverified_complete', 'medium']]);
        assert.equal(s.false_complete_evaluable, false);
    });

    test('provider-schema fields may never claim complete, and must carry the visibility warnings', () => {
        const ps = row('a', 'A', { extraction_source: 'provider_schema', label_source: 'provider_schema' });
        const bad = scoreCase(truth([]), result([ps], { inspection_outcome: 'complete', warnings: ['provider_schema_only', 'visibility_not_established'] }));
        assert.ok(bad.findings.some((f) => f.code === 'provider_schema_overclaim' && f.severity === 'high'));
        const bare = scoreCase(truth([]), result([ps], { inspection_outcome: 'partial', warnings: [] }));
        assert.match(bare.findings.find((f) => f.code === 'provider_schema_overclaim').message, /provider_schema_only/);
        const good = scoreCase(truth([]), result([ps], { inspection_outcome: 'partial', warnings: ['provider_schema_only', 'visibility_not_established'] }));
        assert.equal(good.findings.some((f) => f.code === 'provider_schema_overclaim'), false);
    });

    test('provenance expectation is per row', () => {
        const t = truth([], { provenance: { allowed: ['dom', 'both'], c: 'authored' } });
        const s = scoreCase(t, result([row('a', 'A'), row('b', 'B', { extraction_source: 'provider_schema' })]));
        assert.deepEqual([s.metrics.provenance_expectation.numerator, s.metrics.provenance_expectation.denominator], [1, 2]);
        assert.deepEqual(s.distribution.extraction_source, { dom: 1, provider_schema: 1 });
    });
});
