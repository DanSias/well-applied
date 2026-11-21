// Ashby normalized controls -> the EXISTING worker->Laravel result contract. Pure; no network, page or file access.
//
// Nothing here adds a key to the contract: Laravel validates exactly these field keys and drops the rest. The two enum values
// it needed (label_source and extraction_source 'provider_schema') mirror app/Enums and are diffed by WorkerContractSyncTest.
//
// What reaches the contract: identity (the schema path), the declared title, logical type, required flag, section and the
// options the schema lists. What does NOT, and is returned beside it as `internal` for the milestone that persists it:
// sensitivity, widget, visibility, conditional wording, which form a field came from, and coverage. The contract carries no
// claim of visibility or completeness, and the result is 'partial' because a schema is not a rendered page.
import { LabelSource, ExtractionSource, InspectionOutcome } from '../../result.js';

const ATS = 'ashby';
const LABEL_SOURCES = new Set(Object.values(LabelSource));

export class AshbyContractError extends Error {
    constructor(reason, detail = null) {
        super(detail ? `${reason}: ${detail}` : reason);
        this.reason = reason;
    }
}

function toField(c, i) {
    if (c.position !== i) throw new AshbyContractError('position_mismatch', `control ${i} has position ${c.position}`);
    if (c.provenance?.source !== 'provider_schema') throw new AshbyContractError('not_provider_schema', `control ${i}`);
    if (typeof c.id !== 'string' || c.id === '') throw new AshbyContractError('missing_field_identity', `control ${i}`);
    const text = c.label.text;
    // An empty title stays unresolved with no label: a label is never invented to fill the gap.
    const label = typeof text === 'string' && text !== '' ? { text, source: LabelSource.ProviderSchema } : { text: null, source: LabelSource.Unresolved };
    if (!LABEL_SOURCES.has(label.source)) throw new AshbyContractError('label_source_not_in_contract', label.source);
    // Only options the schema actually lists; Yes/No implied by a Boolean type is not an option the provider declared.
    const labels = c.options?.state === 'known_in_schema' && Array.isArray(c.options.labels) && c.options.labels.length > 0 ? c.options.labels : null;

    return {
        position: i,
        external_field_id: c.id.slice(0, 255),
        raw_label: label.text,
        label_source: label.source,
        // Free-form on the Laravel side (max 100). An unrecognised provider type is kept as 'unknown', never guessed.
        control_type: c.kind,
        required: c.required.value,
        options: labels,
        section: c.section === null ? null : c.section.slice(0, 255),
        extraction_source: ExtractionSource.ProviderSchema,
    };
}

/**
 * @param {object} mapped output of mapAshbyForm()
 * @returns {{fields: object[], internal: object[], coverage: object, warnings: string[], result: object}}
 *   `result` is the contract body a future worker integration would send; `internal` is what stays out of it.
 */
export function toContractResult(mapped) {
    if (mapped?.provider !== ATS || !Array.isArray(mapped.controls)) throw new AshbyContractError('not_an_ashby_mapping');
    const fields = mapped.controls.map(toField);
    const internal = mapped.controls.map((c) => ({
        position: c.position,
        schema_id: c.schema_id,
        form: c.form,
        form_index: c.form_index,
        sensitive: c.sensitive,
        widget: c.widget,
        visibility: c.visibility,
        conditional_hint: c.conditional_hint,
        provenance: c.provenance,
    }));
    const warnings = [...new Set(['provider_schema_only', ...mapped.warnings])];

    return {
        fields,
        internal,
        coverage: mapped.coverage,
        warnings,
        result: {
            status: 'succeeded',
            inspection_outcome: InspectionOutcome.Partial,
            stop_reason: null,
            ats: ATS,
            requested_url: null,
            final_url: null,
            resolved_url: null,
            fields,
            warnings,
        },
    };
}
