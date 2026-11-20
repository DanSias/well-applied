// A logical control, reduced to the EXISTING worker->Laravel field contract (and to the scorer's row shape). Nothing here adds
// to the contract: Laravel validates exactly these keys and drops the rest, and the label and extraction sources are fixed
// enums mirrored by hand (see ../result.js and WorkerContractSyncTest). The richer facts (sensitive, widget, label confidence,
// options coverage, the interaction description) stay in the discovery layer for the milestone that persists them.
import { LabelSource, ExtractionSource } from '../result.js';

const LABEL_SOURCES = new Set(Object.values(LabelSource));
const normaliseForAria = (t) => (t ?? '').trim().toLowerCase();
// The label is stored without its required marker, but the page's accessible name may still carry it ("First Name*"): either
// form means the DOM label was confirmed by the accessibility tree, as it is for the existing extractor.
const namedInAria = (names, text) => [text, `${text}*`, `${text} *`].some((t) => names.has(normaliseForAria(t)));

/**
 * @param {object[]} controls from normalize()
 * @param {{ariaNames?: Set<string>|null}} [options] lower-cased accessible names found anywhere on the page, for the
 *   same cross-check the existing extractor makes (extraction_source 'both' when the label also appears as one)
 */
export function toContractFields(controls, { ariaNames = null } = {}) {
    return controls.map((c, position) => {
        if (!LABEL_SOURCES.has(c.label.source)) throw new Error(`label source '${c.label.source}' is not in the worker/Laravel contract`);
        const labels = c.options && /^known_/.test(c.options.state) && Array.isArray(c.options.labels) && c.options.labels.length > 0 ? c.options.labels : null;

        return {
            position,
            external_field_id: c.id === null ? null : String(c.id).slice(0, 255),
            raw_label: c.label.text,
            label_source: c.label.source,
            // Free-form on the Laravel side (a string, max 100): the normalized kind is what a person reads in the list.
            control_type: c.kind,
            required: c.required.value,
            options: labels,
            section: c.section === null ? null : c.section.slice(0, 255),
            extraction_source: ariaNames && c.label.text && namedInAria(ariaNames, c.label.text) ? ExtractionSource.Both : ExtractionSource.Dom,
        };
    });
}

/** The scorer's common row shape (test/support/discovery-score.js). */
export const toScorable = (c) => ({
    key: c.id,
    label: c.label.text,
    kind: c.kind,
    required: c.required.value,
    sensitive: c.sensitive.value,
    label_confidence: c.label.confidence,
    options: c.options ? { state: c.options.state, count: c.options.count } : null,
    css: c.interaction.primary.css,
});
