// report.json -> report.md. Pure formatting: it adds no numbers of its own.
const pct = (v) => (v === null ? 'n/a' : `${(v * 100).toFixed(1)}%`);
const cell = (m) => (m.denominator === 0 ? `unscored (0 of ${m.unscored.length} cases scorable)` : `${pct(m.value)} (${m.numerator}/${m.denominator}, ${m.scorable_cases} case${m.scorable_cases === 1 ? '' : 's'})`);
const esc = (s) => String(s).replace(/\|/g, '\\|');

export function renderMarkdown(r) {
    const out = [];
    out.push('# Application inspection evaluation', '');
    out.push(`Configuration \`${r.configuration.name}\` · manifest ${r.manifest.version} (\`${r.manifest.sha256.slice(0, 12)}\`) · inspector \`${String(r.inspector.commit).slice(0, 12)}\` (source \`${r.inspector.source_sha256.slice(0, 12)}\`${r.inspector.source_tree_modified ? ', modified' : ''})`, '');
    out.push('Offline evaluation of committed evidence. Each metric is `value (numerator/denominator, scorable cases)`. A case with missing or inapplicable evidence is **unscored**, never counted as a pass or a failure. Synthetic and real-world cases are reported separately and never pooled.', '');
    out.push(`Evidence integrity: **${r.integrity.verified ? 'all files verified' : `${r.integrity.failures.length} FAILED`}**.`);
    for (const f of r.integrity.failures) out.push(`- ${f.case}: ${f.role} \`${f.path}\` is ${f.status}`);
    if (r.unsupported_metrics.length) out.push('', `Unsupported metrics requested (not computed): ${r.unsupported_metrics.map((m) => `\`${m}\``).join(', ')}`);
    out.push('');

    const high = r.findings.filter((f) => f.severity === 'high');
    out.push('## High-severity findings', '');
    if (high.length === 0) out.push('None.');
    for (const f of high) out.push(`- **${f.code}** · ${f.case} (${f.origin}): ${f.message}`);
    out.push('');
    const other = r.findings.filter((f) => f.severity !== 'high');
    if (other.length) out.push('## Other findings', '', ...other.map((f) => `- ${f.severity} **${f.code}** · ${f.case}: ${f.message}`), '');

    const names = Object.keys(r.higher_is_better);
    for (const [origin, a] of Object.entries(r.aggregates_by_origin)) {
        out.push(`## ${origin === 'real_world' ? 'Real-world captured evidence' : origin === 'synthetic' ? 'Synthetic regression fixtures' : origin} (${a.cases} case${a.cases === 1 ? '' : 's'})`, '');
        out.push('| Metric | Result |', '|---|---|');
        for (const n of names) {
            const m = a.metrics[n];
            const sec = m.secondary_inferred_truth ? ` · against inferred truth: ${m.secondary_inferred_truth.numerator}/${m.secondary_inferred_truth.denominator}` : '';
            out.push(`| ${n} | ${cell(m)}${sec} |`);
        }
        out.push(`| false_complete_claims | ${a.false_complete_claims.numerator}/${a.false_complete_claims.denominator} evaluable claims of complete${a.false_complete_claims.case_ids.length ? ` (${a.false_complete_claims.case_ids.join(', ')})` : ''} |`, '');
        out.push(`Provenance of extracted rows: ${Object.entries(a.provenance_distribution.extraction_source).map(([k, v]) => `${k} ${v}`).join(', ') || 'none'}. Label sources: ${Object.entries(a.provenance_distribution.label_source).map(([k, v]) => `${k} ${v}`).join(', ') || 'none'}.`, '');
        const un = names.flatMap((n) => a.metrics[n].unscored.map((u) => `${n}: ${u.case} (${u.reason})`));
        if (un.length) out.push('<details><summary>Unscored</summary>', '', ...un.map((u) => `- ${u}`), '', '</details>', '');
    }

    out.push('## Cases', '', '| Case | Origin | Result basis | Claimed outcome | Findings |', '|---|---|---|---|---|');
    for (const c of r.cases) out.push(`| ${c.id} | ${c.origin} | ${c.result.basis}${c.result.status !== 'ok' && c.result.status !== 'not_loaded' ? ` (${c.result.status})` : ''} | ${c.claimed_outcome ?? '—'} | ${esc(c.findings.map((f) => f.code).join(', ') || '—')} |`);
    out.push('', 'A `captured_live` result is a past production run and cannot change with the inspector under evaluation.', '');
    out.push('## Evidence', '', '| Case | Role | Class | SHA-256 | Status |', '|---|---|---|---|---|');
    for (const c of r.cases) for (const e of c.evidence) out.push(`| ${c.id} | ${e.role} | ${e.class} | \`${e.sha256.slice(0, 12)}\` | ${e.status} |`);

    return `${out.join('\n')}\n`;
}
