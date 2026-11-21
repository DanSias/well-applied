// Compares two evaluation reports. Direction is judged only where the two runs scored the SAME cases for a metric; anything
// else is "not comparable", because a rate over different cases says nothing about a change in the inspector.
import { ratio } from './canon.js';

export function compareReports(a, b) {
    const warnings = [];
    if (a.manifest.sha256 !== b.manifest.sha256) warnings.push(`manifest differs (${a.manifest.version} ${a.manifest.sha256.slice(0, 12)} vs ${b.manifest.version} ${b.manifest.sha256.slice(0, 12)}): case sets and evidence may differ`);
    if (!a.integrity.verified || !b.integrity.verified) warnings.push('at least one report has failed evidence integrity checks');
    if (a.inspector.source_sha256 === b.inspector.source_sha256) warnings.push('both runs used identical inspector source: any difference comes from the configuration, not the code');

    const basesOf = (r) => JSON.stringify(Object.fromEntries(Object.entries(r.aggregates_by_origin).map(([o, x]) => [o, x.evidence_basis_composition])));
    if (basesOf(a) !== basesOf(b)) warnings.push('the evidence bases of the cases differ between the two reports');
    const metrics = [];
    const origins = [...new Set([...Object.keys(a.aggregates_by_origin), ...Object.keys(b.aggregates_by_origin)])].sort();
    for (const origin of origins) {
        const A = a.aggregates_by_origin[origin];
        const B = b.aggregates_by_origin[origin];
        if (!A || !B) {
            metrics.push({ origin, metric: '*', verdict: 'not_comparable', note: `origin present in only one report` });
            continue;
        }
        for (const name of Object.keys(a.higher_is_better)) {
            const x = A.metrics[name];
            const y = B.metrics?.[name];
            if (!y) {
                metrics.push({ origin, metric: name, verdict: 'not_comparable', note: 'metric absent from the second report' });
                continue;
            }
            const sameCases = JSON.stringify(x.unscored.map((u) => u.case).sort()) === JSON.stringify(y.unscored.map((u) => u.case).sort()) && x.scorable_cases === y.scorable_cases;
            const row = { origin, metric: name, before: { value: x.value, numerator: x.numerator, denominator: x.denominator, scorable_cases: x.scorable_cases }, after: { value: y.value, numerator: y.numerator, denominator: y.denominator, scorable_cases: y.scorable_cases } };
            if (x.value === null || y.value === null) metrics.push({ ...row, verdict: 'not_comparable', note: 'unscored in one or both reports' });
            else if (!sameCases) metrics.push({ ...row, verdict: 'not_comparable', note: 'a different set of cases was scorable' });
            else if (x.value === y.value) metrics.push({ ...row, verdict: 'unchanged', delta: 0 });
            else {
                const delta = ratio(Math.round((y.value - x.value) * 10000), 10000);
                metrics.push({ ...row, verdict: (y.value > x.value) === a.higher_is_better[name] ? 'improved' : 'regressed', delta });
            }
        }
        const fa = A.false_complete_claims;
        const fb = B.false_complete_claims;
        metrics.push({ origin, metric: 'false_complete_claims', before: { numerator: fa.numerator, denominator: fa.denominator }, after: { numerator: fb.numerator, denominator: fb.denominator }, verdict: fb.numerator > fa.numerator ? 'regressed' : fb.numerator < fa.numerator ? 'improved' : 'unchanged', ...(fa.denominator !== fb.denominator ? { note: 'the number of evaluable claims of complete differs' } : {}) });
    }

    const key = (f) => `${f.case}|${f.code}`;
    const fa = new Map(a.findings.map((f) => [key(f), f]));
    const fb = new Map(b.findings.map((f) => [key(f), f]));
    const findings = {
        new: [...fb.keys()].filter((k) => !fa.has(k)).sort().map((k) => fb.get(k)),
        resolved: [...fa.keys()].filter((k) => !fb.has(k)).sort().map((k) => fa.get(k)),
    };
    const cases = [];
    for (const c of b.cases) {
        const p = a.cases.find((x) => x.id === c.id);
        if (!p) cases.push({ case: c.id, change: 'added' });
        else if (p.claimed_outcome !== c.claimed_outcome) cases.push({ case: c.id, change: 'outcome', before: p.claimed_outcome, after: c.claimed_outcome });
        else if (p.result.basis === 'captured_live_inspection') continue;
    }
    for (const p of a.cases) if (!b.cases.some((x) => x.id === p.id)) cases.push({ case: p.id, change: 'removed' });

    const verdicts = metrics.map((m) => m.verdict);
    return {
        schema: 'eval-compare/1',
        before: { configuration: a.configuration.name, manifest: a.manifest, inspector: a.inspector },
        after: { configuration: b.configuration.name, manifest: b.manifest, inspector: b.inspector },
        warnings,
        summary: { improved: verdicts.filter((v) => v === 'improved').length, regressed: verdicts.filter((v) => v === 'regressed').length, unchanged: verdicts.filter((v) => v === 'unchanged').length, not_comparable: verdicts.filter((v) => v === 'not_comparable').length },
        metrics,
        findings,
        cases,
    };
}

const f = (v) => (v === null || v === undefined ? 'n/a' : `${(v * 100).toFixed(1)}%`);
export function renderComparison(c) {
    const out = ['# Evaluation comparison', '', `Before: \`${c.before.configuration}\` · inspector \`${String(c.before.inspector.commit).slice(0, 12)}\``, `After: \`${c.after.configuration}\` · inspector \`${String(c.after.inspector.commit).slice(0, 12)}\``, ''];
    for (const w of c.warnings) out.push(`> ${w}`);
    out.push('', `**${c.summary.improved} improved · ${c.summary.regressed} regressed · ${c.summary.unchanged} unchanged · ${c.summary.not_comparable} not comparable**`, '');
    out.push('| Origin | Metric | Before | After | Verdict |', '|---|---|---|---|---|');
    const show = (x) => (x?.denominator === undefined ? 'n/a' : x.value === undefined ? `${x.numerator}/${x.denominator}` : `${f(x.value)} (${x.numerator}/${x.denominator})`);
    for (const m of c.metrics.filter((x) => x.metric !== '*')) out.push(`| ${m.origin} | ${m.metric} | ${show(m.before)} | ${show(m.after)} | ${m.verdict}${m.note ? ` — ${m.note}` : ''} |`);
    for (const m of c.metrics.filter((x) => x.metric === '*')) out.push(`| ${m.origin} | — | — | — | ${m.verdict} — ${m.note} |`);
    out.push('', '## Findings', '');
    for (const x of c.findings.new) out.push(`- NEW ${x.severity} **${x.code}** · ${x.case}: ${x.message}`);
    for (const x of c.findings.resolved) out.push(`- resolved ${x.severity} **${x.code}** · ${x.case}`);
    if (!c.findings.new.length && !c.findings.resolved.length) out.push('No change.');
    out.push('', '## Cases whose claimed outcome changed', '');
    for (const x of c.cases) out.push(`- ${x.case}: ${x.change}${x.before !== undefined ? ` ${x.before} → ${x.after}` : ''}`);
    if (!c.cases.length) out.push('None.');

    return `${out.join('\n')}\n`;
}
