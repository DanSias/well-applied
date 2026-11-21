// Stage 0 evaluation harness over the committed manifest: integrity, determinism, separation, honesty and compare.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync, existsSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { stableJson } from '../eval/src/canon.js';
import { compareReports, renderComparison } from '../eval/src/compare.js';
import { renderMarkdown } from '../eval/src/render.js';
import { runEvaluation } from '../eval/src/run.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const COMMIT = '0'.repeat(40);
const run = (root, config = 'generic-on') => runEvaluation({ root, config, inspectorCommit: COMMIT });
const manifest = () => JSON.parse(readFileSync(path.join(ROOT, 'eval', 'manifest.json'), 'utf8'));

let box;
const sandbox = () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'eval-stage0-'));
    for (const d of ['eval', 'src']) cpSync(path.join(ROOT, d), path.join(dir, d), { recursive: true });
    cpSync(path.join(ROOT, 'test', 'fixtures'), path.join(dir, 'test', 'fixtures'), { recursive: true });

    return dir;
};
before(() => {
    box = [];
});
after(() => box.forEach((d) => rmSync(d, { recursive: true, force: true })));
const fresh = () => {
    const d = sandbox();
    box.push(d);

    return d;
};
const editManifest = (dir, fn) => {
    const f = path.join(dir, 'eval', 'manifest.json');
    const m = JSON.parse(readFileSync(f, 'utf8'));
    fn(m);
    writeFileSync(f, JSON.stringify(m, null, 2));
};

describe('the manifest', () => {
    test('has about ten cases, with synthetic regression fixtures and real-world evidence explicitly separated', () => {
        const m = manifest();
        assert.ok(m.cases.length >= 10 && m.cases.length <= 12, String(m.cases.length));
        assert.deepEqual([...new Set(m.cases.map((c) => c.origin))].sort(), ['real_world', 'synthetic']);
        assert.ok(m.cases.filter((c) => c.origin === 'real_world').map((c) => c.id).join().match(/chainguard.*nerdwallet|nerdwallet.*chainguard/));
        assert.ok(m.cases.filter((c) => c.origin === 'synthetic').every((c) => c.id.startsWith('syn-')));
        assert.match(m.manifest_version, /^\d+\.\d+\.\d+$/);
    });

    test('every evidence file is hash-pinned, and the synthetic fixtures use the SAME hashes as the corpus', () => {
        const corpus = JSON.parse(readFileSync(path.join(ROOT, 'test', 'fixtures', 'universal', 'corpus.json'), 'utf8'));
        for (const c of manifest().cases) {
            for (const e of c.evidence) assert.match(e.sha256, /^[0-9a-f]{64}$/, `${c.id}/${e.role}`);
            if (c.origin !== 'synthetic') continue;
            const fx = corpus.fixtures.find((f) => `syn-${f.id}` === c.id);
            assert.equal(c.evidence.find((e) => e.role === 'fixture_html').sha256, fx.sha256, `${c.id} fixture hash differs from corpus.json`);
        }
    });

    test('every committed evidence file and replayed result verifies', () => {
        const r = run(ROOT);
        assert.equal(r.integrity.verified, true, JSON.stringify(r.integrity.failures));
    });
});

describe('the report', () => {
    test('is deterministic: the same inputs are the same bytes, and nothing in it is a clock reading', () => {
        const a = stableJson(run(ROOT));
        const b = stableJson(run(ROOT));
        assert.equal(a, b);
        assert.equal(renderMarkdown(run(ROOT)), renderMarkdown(run(ROOT)));
        assert.equal(/\d{4}-\d{2}-\d{2}T\d{2}:/.test(a), false, 'no timestamps');
    });

    test('carries the manifest version and hash, the inspector commit and source hash, and each case\'s evidence provenance', () => {
        const r = run(ROOT);
        assert.equal(r.manifest.version, manifest().manifest_version);
        assert.match(r.manifest.sha256, /^[0-9a-f]{64}$/);
        assert.equal(r.inspector.commit, COMMIT);
        assert.match(r.inspector.source_sha256, /^[0-9a-f]{64}$/);
        for (const c of r.cases) {
            assert.ok(c.evidence.length >= 2, c.id);
            for (const e of c.evidence) assert.deepEqual([typeof e.class, e.sha256.length, e.status], ['string', 64, 'verified']);
        }
    });

    test('reports synthetic and real-world results separately and never pools them', () => {
        const r = run(ROOT);
        assert.deepEqual(Object.keys(r.aggregates_by_origin), ['real_world', 'synthetic']);
        assert.equal(r.aggregates_by_origin.real_world.cases, 2);
        assert.equal(r.aggregates_by_origin.synthetic.cases, 9);
        assert.equal(r.aggregates_by_origin.pooled, undefined);
    });

    test('every metric has a numerator, a denominator, a scorable-case count and its unscored cases', () => {
        const r = run(ROOT);
        for (const a of Object.values(r.aggregates_by_origin)) {
            for (const [name, m] of Object.entries(a.metrics)) {
                assert.ok(Number.isInteger(m.numerator) && Number.isInteger(m.denominator) && Number.isInteger(m.scorable_cases) && Array.isArray(m.unscored), name);
                assert.equal(m.value, m.denominator === 0 ? null : Math.round((m.numerator / m.denominator) * 10000) / 10000, name);
                assert.ok(m.scorable_cases + m.unscored.length === a.cases, `${name}: scorable + unscored must account for every case`);
            }
        }
    });

    test('keeps the NerdWallet schema-only case honest: partial, provider_schema, no URL judged, precision unscored', () => {
        const c = run(ROOT).cases.find((x) => x.id === 'real-nerdwallet-258');
        assert.equal(c.claimed_outcome, 'partial');
        assert.equal(c.metrics.application_url_resolution.reason, 'schema_only_result_carries_no_url');
        assert.equal(c.metrics.field_precision.reason, 'truth_not_exhaustive');
        assert.equal(c.metrics.field_recall.status, 'scored');
        assert.equal(c.distribution.extraction_source.provider_schema, 29);
        assert.equal(c.findings.some((f) => f.severity === 'high'), false);
    });

    test('keeps the Chainguard run honest: its known extraction errors show, and its claim of complete is flagged as unverified', () => {
        const c = run(ROOT).cases.find((x) => x.id === 'real-chainguard-4722145006');
        assert.equal(c.result.basis, 'captured_live_inspection');
        assert.equal(c.evidence_basis.live_inspection, true);
        assert.deepEqual(c.matching.extra_rows, [4], 'the duplicated Country control (run #12 row 4) is a spurious row');
        assert.equal(c.metrics.field_type_accuracy.secondary_inferred_truth.denominator, 2);
        assert.deepEqual(c.findings.map((f) => f.code), ['unverified_complete']);
        assert.deepEqual(c.matching.excluded_truth_fields.map((f) => f.key).sort(), ['cover_letter_text', 'race', 'resume_text']);
    });

    test('the committed sample reports are current', () => {
        const sample = JSON.parse(readFileSync(path.join(ROOT, 'eval', 'reports', 'sample', 'generic-on', 'report.json'), 'utf8'));
        const r = runEvaluation({ root: ROOT, config: 'generic-on', inspectorCommit: sample.inspector.commit });
        assert.equal(stableJson(r), stableJson(sample), 'regenerate with: node eval/src/cli.js run --config generic-on --out eval/reports/sample/generic-on --inspector-commit <sha>');
    });
});

describe('what the scores rest on', () => {
    const r = run(ROOT);
    const md = renderMarkdown(r);
    const byId = (id) => r.cases.find((c) => c.id === id);

    test('every case names its evidence basis in the JSON, with whether it is a live inspection', () => {
        for (const c of r.cases) {
            assert.ok(['captured_live_inspection', 'offline_provider_schema_snapshot', 'synthetic_fixture_replay'].includes(c.evidence_basis.id), c.id);
            assert.equal(c.result.basis, c.evidence_basis.id);
            assert.equal(typeof c.evidence_basis.live_inspection, 'boolean');
            assert.ok(c.evidence_basis.statement.length > 20);
        }
        assert.deepEqual(r.cases.filter((c) => c.evidence_basis.live_inspection).map((c) => c.id), ['real-chainguard-4722145006']);
        assert.ok(r.cases.filter((c) => c.origin === 'synthetic').every((c) => c.evidence_basis.id === 'synthetic_fixture_replay'));
    });

    test('NerdWallet is stated, in JSON and Markdown, to be an offline schema-adapter result and not a live Ashby inspection', () => {
        const c = byId('real-nerdwallet-258');
        assert.equal(c.evidence_basis.id, 'offline_provider_schema_snapshot');
        assert.equal(c.evidence_basis.live_inspection, false);
        assert.match(c.evidence_basis.case_note, /offline Ashby schema adapter/);
        assert.match(c.evidence_basis.case_note, /NOT a successful live Ashby inspection/);
        assert.match(md, /\*\*real-nerdwallet-258\*\*: The scored result is the output of the offline Ashby schema adapter/);
        assert.match(md, /\| real-nerdwallet-258 \| real_world \| Offline provider-schema snapshot \(not a live inspection\)/);
    });

    test('the report says outright that no live-inspection success rate is measured, and that pooled figures are not one', () => {
        assert.equal(r.live_inspection.cases, 1);
        assert.match(r.live_inspection.statement, /No live-inspection success rate is measured/);
        assert.match(md, /No live-inspection success rate is measured/);
        assert.equal(r.aggregates_by_origin.real_world.mixed_evidence, true);
        assert.match(r.aggregates_by_origin.real_world.interpretation, /NOT a measured live-inspection success rate/);
        assert.equal(r.aggregates_by_origin.synthetic.mixed_evidence, false);
        assert.match(md, /\*\*Mixed evidence\*\*/);
    });

    test('the aggregates are preserved, and the same metrics are also given per evidence basis without pooling', () => {
        assert.deepEqual(Object.keys(r.aggregates_by_evidence_basis), ['captured_live_inspection', 'offline_provider_schema_snapshot', 'synthetic_fixture_replay']);
        assert.equal(r.aggregates_by_origin.real_world.metrics.field_recall.denominator, r.aggregates_by_evidence_basis.captured_live_inspection.metrics.field_recall.denominator + r.aggregates_by_evidence_basis.offline_provider_schema_snapshot.metrics.field_recall.denominator);
        assert.equal(r.aggregates_by_evidence_basis.offline_provider_schema_snapshot.metrics.field_precision.scorable_cases, 0, 'a schema snapshot is never scored for precision here');
        assert.match(md, /## Metrics by evidence basis/);
        assert.match(md, /### Captured live inspection \(1 case, live inspection\)/);
        assert.match(md, /### Offline provider-schema snapshot \(1 case, not a live inspection\)/);
    });

    test('a different mix of evidence bases is called out by compare', () => {
        const b = structuredClone(r);
        b.aggregates_by_origin.real_world.evidence_basis_composition = { captured_live_inspection: 2 };
        assert.ok(compareReports(r, b).warnings.some((w) => /evidence bases/.test(w)));
    });
});

describe('honesty about bad input', () => {
    test('altered evidence is detected, the case is not scored from it, and the CLI exits 3', () => {
        const dir = fresh();
        const f = path.join(dir, 'test/fixtures/universal/expected/unknown-ats-form.expected.json');
        writeFileSync(f, readFileSync(f, 'utf8').replace('"Email"', '"E-mail"'));
        const r = run(dir);
        const c = r.cases.find((x) => x.id === 'syn-unknown-ats-form');
        assert.equal(r.integrity.verified, false);
        assert.deepEqual(r.integrity.failures.map((x) => [x.case, x.role, x.status]), [['syn-unknown-ats-form', 'expected', 'altered']]);
        assert.ok(c.findings.some((x) => x.code === 'evidence_altered' && x.severity === 'high'));
        assert.ok(Object.values(c.metrics).every((m) => m.status === 'unscored' && m.reason === 'evidence_altered'));
        assert.ok(r.findings.some((x) => x.code === 'evidence_altered'));
        const cli = spawnSync('node', [path.join(dir, 'eval/src/cli.js'), 'run', '--config', 'generic-on', '--out', path.join(dir, 'out')], { encoding: 'utf8' });
        assert.equal(cli.status, 3, cli.stdout + cli.stderr);
    });

    test('an altered replayed result is detected too', () => {
        const dir = fresh();
        const f = path.join(dir, 'eval/results/generic-on/syn-multi-step.result.json');
        writeFileSync(f, readFileSync(f, 'utf8').replace('"partial"', '"complete"'));
        const c = run(dir).cases.find((x) => x.id === 'syn-multi-step');
        assert.ok(c.findings.some((x) => x.code === 'evidence_altered'));
        assert.equal(c.metrics.field_recall.status, 'unscored');
    });

    test('missing evidence is unscored with its own reason, not a failure of the inspector', () => {
        const dir = fresh();
        unlinkSync(path.join(dir, 'test/fixtures/universal/expected/sensitive-demographics.expected.json'));
        const c = run(dir).cases.find((x) => x.id === 'syn-sensitive-demographics');
        assert.ok(Object.values(c.metrics).every((m) => m.reason === 'evidence_missing'));
        assert.ok(c.findings.some((x) => x.code === 'evidence_missing'));
    });

    test('missing ground truth for a metric is unscored for that case and leaves the others scored', () => {
        const dir = fresh();
        editManifest(dir, (m) => {
            const c = m.cases.find((x) => x.id === 'syn-unknown-ats-form');
            delete c.expect.platform;
            delete c.expect.application_url;
        });
        const r = run(dir);
        const c = r.cases.find((x) => x.id === 'syn-unknown-ats-form');
        assert.equal(c.metrics.platform_classification.reason, 'no_ground_truth');
        assert.equal(c.metrics.field_recall.status, 'scored');
        const a = r.aggregates_by_origin.synthetic.metrics.platform_classification;
        assert.deepEqual([a.denominator, a.scorable_cases], [8, 8], 'the unscored case is excluded, not counted as a pass or a failure');
        assert.deepEqual(a.unscored, [{ case: 'syn-unknown-ats-form', reason: 'no_ground_truth' }]);
    });

    test('a case with no replayed result for the configuration is unscored for it', () => {
        const dir = fresh();
        unlinkSync(path.join(dir, 'eval/results/generic-on/syn-multi-step.result.json'));
        const idx = path.join(dir, 'eval/results/INDEX.json');
        const i = JSON.parse(readFileSync(idx, 'utf8'));
        delete i['generic-on']['syn-multi-step'];
        writeFileSync(idx, JSON.stringify(i));
        const c = run(dir).cases.find((x) => x.id === 'syn-multi-step');
        assert.equal(c.metrics.field_recall.reason, 'no_result_for_configuration');
        assert.equal(c.result.status, 'absent');
    });

    test('an unsupported metric is listed as unsupported and is never computed or defaulted', () => {
        const dir = fresh();
        editManifest(dir, (m) => m.metrics.push('median_latency', 'coverage_of_conditional_fields'));
        const r = run(dir);
        assert.deepEqual(r.unsupported_metrics, ['coverage_of_conditional_fields', 'median_latency']);
        for (const a of Object.values(r.aggregates_by_origin)) assert.equal(a.metrics.median_latency, undefined);
        assert.match(renderMarkdown(r), /Unsupported metrics requested/);
    });

    test('a high-severity false-complete claim is reported on its own and listed first', () => {
        const dir = fresh();
        const f = path.join(dir, 'test/fixtures/ashby/expected/nerdwallet-258.contract.result.json');
        const bad = JSON.parse(readFileSync(f, 'utf8'));
        bad.inspection_outcome = 'complete';
        writeFileSync(f, JSON.stringify(bad));
        editManifest(dir, (m) => {
            m.cases.find((c) => c.id === 'real-nerdwallet-258').evidence.find((e) => e.role === 'contract_result').sha256 = 'x';
        });
        // The hash no longer matches either: reseal as a deliberate change would.
        const sealed = spawnSync('node', [path.join(dir, 'eval/tools/seal-manifest.mjs')], { encoding: 'utf8' });
        assert.equal(sealed.status, 0, sealed.stderr);
        const r = run(dir);
        assert.equal(r.integrity.verified, true);
        const codes = r.findings.filter((f) => f.severity === 'high').map((f) => f.code).sort();
        assert.deepEqual(codes, ['false_complete', 'provider_schema_overclaim']);
        assert.equal(r.findings[0].severity, 'high');
        assert.deepEqual(r.aggregates_by_origin.real_world.false_complete_claims.case_ids, ['real-nerdwallet-258']);
        assert.match(renderMarkdown(r), /## High-severity findings\n\n- \*\*false_complete\*\*/);
    });
});

describe('compare', () => {
    const off = () => run(ROOT, 'generic-off');
    const on = () => run(ROOT, 'generic-on');

    test('identifies the improvements generic discovery makes, and no regressions', () => {
        const c = compareReports(off(), on());
        const synth = (m) => c.metrics.find((x) => x.origin === 'synthetic' && x.metric === m);
        assert.equal(synth('field_recall').verdict, 'improved', 'generic-off found nothing on the forms it could not read: a real zero, not an unscored case');
        assert.equal(synth('field_recall').before.numerator, 0);
        assert.equal(synth('outcome_classification').verdict, 'improved');
        assert.equal(synth('false_barrier_rate').verdict, 'improved');
        assert.equal(synth('application_url_resolution').verdict, 'improved');
        assert.equal(c.summary.regressed, 0, JSON.stringify(c.metrics.filter((m) => m.verdict === 'regressed')));
        const real = c.metrics.filter((m) => m.origin === 'real_world');
        assert.ok(real.every((m) => m.verdict === 'unchanged' || m.verdict === 'not_comparable'), 'captured evidence cannot move with the inspector');
    });

    test('identifies regressions, in either direction of "better"', () => {
        const a = on();
        const b = structuredClone(a);
        const m = b.aggregates_by_origin.synthetic.metrics;
        m.outcome_classification = { ...m.outcome_classification, numerator: 8, value: 8 / 9 };
        m.unresolved_label_rate = { ...m.unresolved_label_rate, numerator: 5, value: 5 / 53 };
        b.aggregates_by_origin.synthetic.false_complete_claims = { numerator: 1, denominator: 4, scorable_cases: 4, case_ids: ['syn-multi-step'] };
        const c = compareReports(a, b);
        const verdict = (n) => c.metrics.find((x) => x.origin === 'synthetic' && x.metric === n).verdict;
        assert.deepEqual([verdict('outcome_classification'), verdict('unresolved_label_rate'), verdict('false_complete_claims')], ['regressed', 'regressed', 'regressed']);
        assert.equal(compareReports(b, a).metrics.find((x) => x.origin === 'synthetic' && x.metric === 'unresolved_label_rate').verdict, 'improved');
    });

    test('does not call a change in the scorable case set an improvement', () => {
        const a = on();
        const b = structuredClone(a);
        const m = b.aggregates_by_origin.synthetic.metrics.platform_classification;
        m.unscored = [{ case: 'syn-multi-step', reason: 'no_ground_truth' }];
        m.scorable_cases = 8;
        m.denominator = 8;
        m.numerator = 8;
        assert.equal(compareReports(a, b).metrics.find((x) => x.origin === 'synthetic' && x.metric === 'platform_classification').verdict, 'not_comparable');
    });

    test('reports new and resolved findings, warns about a different manifest, and is deterministic', () => {
        const a = on();
        const b = structuredClone(a);
        b.findings = [...b.findings, { case: 'syn-multi-step', origin: 'synthetic', severity: 'high', code: 'false_complete', message: 'x' }];
        b.manifest = { ...b.manifest, sha256: 'f'.repeat(64) };
        const c = compareReports(a, b);
        assert.deepEqual(c.findings.new.map((f) => f.code), ['false_complete']);
        assert.ok(c.warnings.some((w) => /manifest differs/.test(w)));
        assert.equal(stableJson(compareReports(a, b)), stableJson(c));
        assert.equal(renderComparison(c), renderComparison(compareReports(a, b)));
        assert.match(renderComparison(c), /NEW high \*\*false_complete\*\*/);
    });

    test('the CLI compares two report files and can fail on regression', () => {
        const dir = fresh();
        const a = on();
        const b = structuredClone(a);
        b.aggregates_by_origin.synthetic.false_complete_claims = { numerator: 1, denominator: 4, scorable_cases: 4, case_ids: ['syn-multi-step'] };
        writeFileSync(path.join(dir, 'a.json'), stableJson(a));
        writeFileSync(path.join(dir, 'b.json'), stableJson(b));
        const cli = (...extra) => spawnSync('node', [path.join(ROOT, 'eval/src/cli.js'), 'compare', path.join(dir, 'a.json'), path.join(dir, 'b.json'), ...extra], { encoding: 'utf8' });
        assert.equal(cli().status, 0);
        assert.equal(cli('--fail-on-regression').status, 1);
        assert.equal(existsSync(path.join(dir, 'cmp')), false);
        assert.equal(cli('--out', path.join(dir, 'cmp')).status, 0);
        assert.ok(existsSync(path.join(dir, 'cmp', 'compare.json')) && existsSync(path.join(dir, 'cmp', 'compare.md')));
    });
});
