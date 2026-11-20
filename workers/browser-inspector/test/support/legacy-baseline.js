// Runs the EXISTING Greenhouse extractor (src/extractors/greenhouse.js, unchanged) over any page and reduces its rows to the
// scorer's common shape, so the legacy behaviour can be measured against the M1 criteria as a baseline.
//
// The extractor's contract rows carry no DOM reference (its internal `locator` is dropped), but a reference is needed to ask
// "is this row an element that must not be reported?". So this adapter re-derives the element list with the extractor's OWN
// selection rules (copied below, verbatim in behaviour) and requires that it agrees with the extractor's row count. If the
// two ever disagree the adapter throws, rather than scoring against the wrong elements.
import { extract } from '../../src/extractors/greenhouse.js';

// Copied from src/extractors/greenhouse.js domExtract(); runs in the page.
function selectLegacyElements() {
    function visible(el) {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    }
    const controlSelector = [
        'input:not([type="hidden"]):not([type="submit"]):not([type="button"])',
        'textarea',
        'select',
        '[role="radio"]',
        '[role="checkbox"]',
        '[role="combobox"]',
        '[role="listbox"]',
        '[role="switch"]',
    ].join(',');
    function cssPath(el) {
        if (el.id) return `#${CSS.escape(el.id)}`;
        let node = el;
        const parts = [];
        for (let i = 0; i < 4 && node && node !== document.body; i++) {
            let idx = 1;
            let sib = node;
            while ((sib = sib.previousElementSibling)) if (sib.tagName === node.tagName) idx++;
            parts.unshift(`${node.tagName.toLowerCase()}:nth-of-type(${idx})`);
            node = node.parentElement;
        }
        return parts.join(' > ');
    }

    return Array.from(document.querySelectorAll(controlSelector)).filter(visible).map(cssPath);
}

const confidenceOf = (labelSource) => (labelSource === 'inferred_proximity' || labelSource === 'dom_placeholder' ? 'inferred' : labelSource === 'unresolved' ? 'unresolved' : 'structural');

/** @returns {Promise<Array<object>>} scorable rows */
export async function legacyScorableRows(page) {
    const { fields } = await extract(page);
    const css = await page.evaluate(selectLegacyElements);
    if (css.length !== fields.length) throw new Error(`legacy adapter disagrees with the extractor: ${css.length} elements vs ${fields.length} rows`);

    return fields.map((f, i) => ({
        key: f.external_field_id,
        label: f.raw_label,
        kind: f.control_type,
        required: f.required,
        sensitive: false, // the legacy contract has no sensitive flag: it reports none
        label_confidence: confidenceOf(f.label_source),
        options: Array.isArray(f.options) ? { state: 'known_in_dom', count: f.options.length } : { state: 'unknown' },
        css: css[i],
    }));
}
