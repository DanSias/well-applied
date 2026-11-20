// Generic, read-only form discovery. The one entry point:
//
//   discoverForm({ url, evaluate, ariaNames })
//
// `evaluate` is a READ-ONLY handle (see page-adapter.js): it runs the one collector function in the page and returns plain
// data. Nothing here is given a Playwright Page, so nothing here can click, type, focus, select, upload, navigate or submit.
import { collectSignals } from './collect.js';
import { normalize, outcomeFor } from './normalize.js';
import { toContractFields } from './contract.js';

/**
 * @param {{url: string, evaluate: (fn: Function) => Promise<any>, ariaNames?: (() => Promise<Set<string>>)|null}} reader
 * @returns {Promise<{controls: object[], fields: object[], coverage: object, warnings: string[], inspection_outcome: string, stop_reason: string|null, diagnostics: object}>}
 */
export async function discoverForm({ url, evaluate, ariaNames = null }) {
    const signals = await evaluate(collectSignals);
    const { controls, coverage, warnings } = normalize(signals);
    const outcome = outcomeFor({ controls, coverage });

    let names = null;
    const extraWarnings = [];
    if (ariaNames) {
        try {
            names = await ariaNames();
        } catch {
            extraWarnings.push('aria_snapshot_unavailable');
        }
    }

    return {
        controls,
        fields: toContractFields(controls, { ariaNames: names }),
        coverage,
        warnings: [...warnings, ...extraWarnings],
        ...outcome,
        diagnostics: {
            field_count: controls.length,
            unresolved_label_count: coverage.unresolved_labels,
            hidden_field_count: signals.counts.hiddenInputs,
            button_count: signals.counts.buttons,
        },
        url,
    };
}

export { collectSignals, normalize, outcomeFor, toContractFields };
