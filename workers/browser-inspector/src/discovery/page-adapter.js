// The ONLY place in discovery that touches a Playwright Page, and only for two read operations: evaluate a collector, and read
// the page's accessibility snapshot. The handle it returns refuses to run any function that is not on its allow-list, so even
// code holding the handle cannot ask the page to do anything but be read.
import { collectSignals } from './collect.js';

const READERS = new Set([collectSignals]);

/**
 * @param {import('playwright').Page} page
 * @param {{readers?: Function[]}} [options] tests may allow additional reader functions; production never does
 */
export function readOnlyPage(page, { readers = [] } = {}) {
    const allowed = new Set([...READERS, ...readers]);

    return {
        url: page.url(),
        evaluate: async (fn, arg) => {
            if (!allowed.has(fn)) throw new Error('read-only page: this function is not an approved reader');

            return page.evaluate(fn, arg);
        },
        // The same whole-page cross-check the existing extractor makes: quoted accessible names, lower-cased.
        ariaNames: async () => {
            const yaml = await page.locator('body').ariaSnapshot();
            const names = new Set();
            for (const m of yaml.matchAll(/"([^"]+)"/g)) names.add(m[1].trim().toLowerCase());

            return names;
        },
    };
}
