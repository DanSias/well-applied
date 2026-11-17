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
                requiresSelection: false,
                selectionSignals: [],
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

        // Strong signals: a person must choose a suggestion. Weak signals:
        // composite-looking, but typing into them works (never a reason to skip).
        const strong: string[] = [];
        const weak: string[] = [];

        if (
            el.getAttribute('role') === 'combobox' ||
            el.closest('[role="combobox"]')
        )
            strong.push('role_combobox');
        const ac = el.getAttribute('aria-autocomplete');
        if (ac === 'list' || ac === 'both')
            strong.push('aria_autocomplete_list');
        else if (ac && ac !== 'none') weak.push('aria_autocomplete');
        const popup = el.getAttribute('aria-haspopup');
        if (popup === 'listbox') strong.push('aria_haspopup_listbox');
        else if (popup && popup !== 'false') weak.push('aria_haspopup');

        for (const attr of ['aria-controls', 'aria-owns']) {
            const refs = (el.getAttribute(attr) ?? '')
                .split(/\s+/)
                .filter(Boolean);
            if (refs.length === 0) continue;
            const isList = refs.some((rid) => {
                const t = document.getElementById(rid);
                return (
                    !!t &&
                    (t.getAttribute('role') === 'listbox' ||
                        !!t.querySelector('[role="option"]'))
                );
            });
            (isList ? strong : weak).push(
                isList ? 'listbox_relationship' : 'aria_controls',
            );
        }
        if (el.hasAttribute('aria-expanded')) weak.push('aria_expanded');
        // A native datalist accepts free text: composite-looking, not a skip reason.
        if (el.hasAttribute('list')) weak.push('datalist');

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
        if (widgetClass) weak.push('widget_class');

        // The nearest ancestor (up to 3 levels) that holds this control and no other
        // field: the control's own wrapper.
        const fieldsIn = (a: Element) =>
            a.querySelectorAll('input:not([type="hidden"]), textarea, select')
                .length;
        let wrapper: Element | null = el.parentElement;
        for (
            let a: Element | null = el.parentElement, depth = 0;
            a && depth < 3;
            a = a.parentElement, depth++
        ) {
            if (fieldsIn(a) === 1) wrapper = a;
            else break;
        }

        // Hidden inputs that belong to this control: same name stem.
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
                // Related means the same name, or the same name plus a lookup-style affix
                // (selectedLocation, location_id, location-search). A merely overlapping name
                // such as email / email_confirmation is NOT related.
                const strip = (longer: string, shorter: string) =>
                    longer.startsWith(shorter)
                        ? longer.slice(shorter.length)
                        : longer.endsWith(shorter)
                          ? longer.slice(0, longer.length - shorter.length)
                          : null;
                const affix =
                    /^(search|query|input|text|display|label|field|autocomplete|typeahead|lookup|selected|chosen|resolved|id|value|key|code|place)$/;
                let related = false;
                if (theirs !== '' && mine !== '') {
                    if (theirs === mine) related = true;
                    else {
                        const rest =
                            theirs.length > mine.length
                                ? strip(theirs, mine)
                                : strip(mine, theirs);
                        related = rest !== null && affix.test(rest);
                    }
                }
                if (related && !hidden.includes(h)) hidden.push(h);
            }
        }
        const selectedValueInput = hidden.some((h) =>
            /selected|chosen|resolved|lookup|place/i.test(
                `${h.getAttribute('name') ?? ''} ${h.id}`,
            ),
        );

        // A suggestion list/dropdown inside the control's own wrapper.
        let suggestionContainer = false;
        if (wrapper) {
            if (wrapper.querySelector('[role="listbox"], [role="option"]'))
                suggestionContainer = true;
            else
                for (const d of wrapper.querySelectorAll('*')) {
                    if (
                        d !== el &&
                        /dropdown|suggest|typeahead|listbox|autocomplete-(results|list|menu)/i.test(
                            d.className?.toString?.() ?? '',
                        )
                    ) {
                        suggestionContainer = true;
                        break;
                    }
                }
        }

        if (hidden.length > 0) weak.push('related_hidden_input');
        if (suggestionContainer) weak.push('suggestion_container');
        // The two structural pairings that mean "choose a suggestion":
        if (selectedValueInput) strong.push('selected_value_input');
        if (hidden.length > 0 && suggestionContainer)
            strong.push('hidden_input_with_suggestion_list');

        const signals = [...new Set([...strong, ...weak])];
        const selectionSignals = [...new Set(strong)];
        return {
            visible,
            visibleValue: el.value,
            // Needs its underlying value confirmed: a strong trait or a related hidden input.
            // Weak traits alone (datalist, class names, aria-expanded) are ordinary inputs.
            composite: strong.length > 0 || hidden.length > 0,
            signals,
            requiresSelection: selectionSignals.length > 0,
            selectionSignals,
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

export type ParserObservation =
    | 'success'
    | 'failure'
    | 'working'
    | 'none'
    | 'conflict';

/**
 * Reads (never acts on) a resume-parser indicator: which of the adapter's
 * success / failure / working elements is visibly displayed right now.
 */
export async function readParserObservation(
    page: Page,
    probe: { success: string; failure: string; working: string },
): Promise<ParserObservation> {
    return page.evaluate(
        ([success, failure, working]) => {
            const shown = (sel: string) => {
                const el = document.querySelector(sel);
                if (!el) return false;
                const r = el.getBoundingClientRect();
                return (
                    el.checkVisibility({
                        checkOpacity: true,
                        checkVisibilityCSS: true,
                    }) &&
                    r.width >= 1 &&
                    r.height >= 1
                );
            };
            const s = shown(success as string);
            const f = shown(failure as string);
            if (s && f) return 'conflict';
            if (s) return 'success';
            if (f) return 'failure';
            if (shown(working as string)) return 'working';
            return 'none';
        },
        [probe.success, probe.failure, probe.working] as const,
    );
}

/** Non-sensitive field values as the form displays them now. Read-only. */
export async function readDisplayedValue(
    page: Page,
    field: { id: string; kind: string; options: string[] | null },
): Promise<string | null> {
    try {
        if (field.kind === 'text' || field.kind === 'textarea')
            return (await readControlState(page, field.id)).visibleValue;
        if (field.kind === 'select')
            return await readSelectedOption(page, field.id);
        if (field.kind === 'radio') {
            const i = await readCheckedRadio(page, field.id);
            return i >= 0 ? (field.options?.[i] ?? '') : null;
        }
    } catch {
        return null;
    }
    return null;
}
