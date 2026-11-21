// Field matching: deterministic, one-to-one, and honest about ambiguity. A match is made only when it is unambiguous on BOTH
// sides; anything else is reported as ambiguous and excluded from every count rather than guessed at.
export const normaliseLabel = (s) => (s ?? '').toLowerCase().replace(/\s+/g, ' ').replace(/\*+\s*$/, '').trim();

const MIN_PREFIX = 20; // a truncated reference label must still be long enough to identify a question

const labelMatches = (truth, row) => {
    const t = normaliseLabel(truth.label);
    const r = normaliseLabel(row.raw_label);
    if (t === '' || r === '') return false;

    return truth.label_truncated ? t.length >= MIN_PREFIX && r.startsWith(t) : r === t;
};

/**
 * @param {{key:string, ids:string[], label:string|null, label_truncated?:boolean}[]} truthFields scorable truth fields only
 * @param {{external_field_id?:string|null, raw_label?:string|null}[]} rows
 */
export function matchFields(truthFields, rows) {
    const matched = [];
    const duplicates = [];
    const ambiguous = [];
    const claimedTruth = new Set();
    const settledRows = new Set();
    const ambiguousTruth = new Set();

    // 1. Identity: the control's own id (or name) is the strongest evidence.
    rows.forEach((row, i) => {
        const id = row.external_field_id;
        if (!id) return;
        const cands = truthFields.filter((t) => t.ids.includes(id));
        if (cands.length === 0) return;
        settledRows.add(i);
        if (cands.length > 1) {
            ambiguous.push({ row: i, reason: 'id_matches_several_truth_fields', truth: cands.map((c) => c.key).sort() });
            cands.forEach((c) => ambiguousTruth.add(c.key));
        } else if (claimedTruth.has(cands[0].key)) duplicates.push({ row: i, truth: cands[0].key });
        else {
            claimedTruth.add(cands[0].key);
            matched.push({ truth: cands[0].key, row: i, by: 'id' });
        }
    });

    // 2. Label, only among what identity left over, and only when it is one-to-one.
    const freeRows = rows.map((_, i) => i).filter((i) => !settledRows.has(i));
    const freeTruth = truthFields.filter((t) => !claimedTruth.has(t.key) && !ambiguousTruth.has(t.key));
    const rowCands = new Map(freeRows.map((i) => [i, freeTruth.filter((t) => labelMatches(t, rows[i]))]));
    const truthCands = new Map(freeTruth.map((t) => [t.key, freeRows.filter((i) => labelMatches(t, rows[i]))]));
    const extra = [];
    for (const i of freeRows) {
        const cands = rowCands.get(i);
        if (cands.length === 0) extra.push(i);
        else if (cands.length === 1 && truthCands.get(cands[0].key).length === 1) {
            claimedTruth.add(cands[0].key);
            matched.push({ truth: cands[0].key, row: i, by: 'label' });
        } else {
            ambiguous.push({ row: i, reason: 'label_matches_several', truth: cands.map((c) => c.key).sort() });
            cands.forEach((c) => ambiguousTruth.add(c.key));
        }
    }
    const missed = truthFields.filter((t) => !claimedTruth.has(t.key) && !ambiguousTruth.has(t.key)).map((t) => t.key);

    return { matched, missed, extra, duplicates, ambiguous, ambiguous_truth: [...ambiguousTruth].sort() };
}
