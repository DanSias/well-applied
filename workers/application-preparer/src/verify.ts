// Read-only verification of what is actually in the page after filling.
import type { Page } from 'playwright';
import type { ControlState } from './verdict.ts';

export async function readTextValue(page: Page, id: string): Promise<string> {
    return page
        .locator(`[data-wa-id="${id}"]`)
        .first()
        .inputValue({ timeout: 5_000 });
}

/**
 * State of the user-visible control for a text field, read from the page:
 * strict visibility, the displayed value, generic composite/autocomplete
 * traits, and the values of hidden inputs that belong to it. No selector is
 * specific to any ATS.
 */
export async function readControlState(
    page: Page,
    id: string,
): Promise<ControlState> {
    return page.evaluate((fid) => {
        const el = document.querySelector(
            `input[data-wa-id="${fid}"], textarea[data-wa-id="${fid}"]`,
        ) as HTMLInputElement | HTMLTextAreaElement | null;
        if (!el)
            return {
                visible: false,
                visibleValue: '',
                composite: false,
                signals: ['control_missing'],
                hiddenValues: [],
            };

        const r = el.getBoundingClientRect();
        const offscreen =
            r.left + window.scrollX + r.width <= 0 ||
            r.top + window.scrollY + r.height <= 0;
        const visible =
            el.checkVisibility({
                checkOpacity: true,
                checkVisibilityCSS: true,
            }) &&
            r.width >= 1 &&
            r.height >= 1 &&
            !offscreen;

        const signals: string[] = [];
        const combo = el.closest('[role="combobox"]');
        if (el.getAttribute('role') === 'combobox' || combo)
            signals.push('role_combobox');
        const ac = el.getAttribute('aria-autocomplete');
        if (ac && ac !== 'none') signals.push('aria_autocomplete');
        const popup = el.getAttribute('aria-haspopup');
        if (popup && popup !== 'false') signals.push('aria_haspopup');
        if (el.hasAttribute('aria-controls') || el.hasAttribute('aria-owns'))
            signals.push('aria_controls');
        if (el.hasAttribute('aria-expanded')) signals.push('aria_expanded');
        if (el.hasAttribute('list')) signals.push('datalist');
        let widgetClass = false;
        for (
            let a: Element | null = el, depth = 0;
            a && depth < 4;
            a = a.parentElement, depth++
        ) {
            if (
                /autocomplete|typeahead|combobox|suggest/i.test(
                    a.className?.toString?.() ?? '',
                )
            )
                widgetClass = true;
        }
        if (widgetClass) signals.push('widget_class');

        // Hidden inputs near the control that belong to it: same name stem.
        const stem = (n: string | null) =>
            (n ?? '').toLowerCase().replace(/[^a-z0-9]/g, '');
        const mine = stem(el.getAttribute('name')) || stem(el.id);
        const hidden: HTMLInputElement[] = [];
        for (
            let a: Element | null = el.parentElement, depth = 0;
            a && depth < 3;
            a = a.parentElement, depth++
        ) {
            for (const h of a.querySelectorAll(
                'input[type="hidden"]',
            ) as NodeListOf<HTMLInputElement>) {
                const theirs = stem(h.getAttribute('name')) || stem(h.id);
                const related =
                    theirs !== '' &&
                    mine !== '' &&
                    (mine.includes(theirs) || theirs.includes(mine));
                if (related && !hidden.includes(h)) hidden.push(h);
            }
        }
        if (hidden.length > 0) signals.push('related_hidden_input');

        // A related hidden input implies a composite; pure ARIA traits do too.
        return {
            visible,
            visibleValue: el.value,
            composite: signals.length > 0,
            signals,
            hiddenValues: hidden.map((h) => h.value),
        };
    }, id);
}

export async function readSelectedOption(
    page: Page,
    id: string,
): Promise<string | null> {
    return page.evaluate((fid) => {
        const el = document.querySelector(
            `select[data-wa-id="${fid}"]`,
        ) as HTMLSelectElement | null;
        const o = el?.selectedOptions[0];
        return o && o.value !== ''
            ? (o.textContent ?? '').replace(/\s+/g, ' ').trim()
            : null;
    }, id);
}

/** Index of the checked radio in the group, or -1. */
export async function readCheckedRadio(
    page: Page,
    id: string,
): Promise<number> {
    return page.evaluate((fid) => {
        const radios = [
            ...document.querySelectorAll(
                `input[type="radio"][data-wa-id="${fid}"]`,
            ),
        ] as HTMLInputElement[];
        const checked = radios.find((r) => r.checked);
        return checked ? Number(checked.getAttribute('data-wa-opt')) : -1;
    }, id);
}

export interface FileObservation {
    name: string | null;
    size: number | null;
    /** SHA-256 of the bytes the BROWSER holds for the input, or null if unavailable. */
    sha256: string | null;
    /** Input was empty (some widgets clear it) but the file name is visible in the page text. */
    nameInPageText: boolean;
}

export async function observeFileInput(
    page: Page,
    id: string,
    expectedName: string,
): Promise<FileObservation> {
    return page.evaluate(
        async ([fid, expected]) => {
            const el = document.querySelector(
                `input[type="file"][data-wa-id="${fid}"]`,
            ) as HTMLInputElement | null;
            const file = el?.files?.[0];
            const nameInPageText = (document.body.innerText || '').includes(
                expected as string,
            );
            if (!file)
                return { name: null, size: null, sha256: null, nameInPageText };
            let sha256: string | null = null;
            try {
                const digest = await crypto.subtle.digest(
                    'SHA-256',
                    await file.arrayBuffer(),
                );
                sha256 = [...new Uint8Array(digest)]
                    .map((b) => b.toString(16).padStart(2, '0'))
                    .join('');
            } catch {
                sha256 = null;
            }
            return { name: file.name, size: file.size, sha256, nameInPageText };
        },
        [id, expectedName] as const,
    );
}

const CONFIRMATION =
    /thank you for (applying|your application|submitting)|application (has been )?(submitted|received|sent)|we(?:'ve| have) received your application|successfully (applied|submitted)/i;

export interface PageSignals {
    url: string;
    confirmationText: string | null;
}

export async function readPageSignals(page: Page): Promise<PageSignals> {
    const text = await page
        .evaluate(() => document.body?.innerText ?? '')
        .catch(() => '');
    const m = CONFIRMATION.exec(text);
    return { url: page.url(), confirmationText: m ? m[0] : null };
}
