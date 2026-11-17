// Read-only verification of what is actually in the page after filling.
import type { Page } from 'playwright';

export async function readTextValue(page: Page, id: string): Promise<string> {
    return page.locator(`[data-wa-id="${id}"]`).first().inputValue({ timeout: 5_000 });
}

export async function readSelectedOption(page: Page, id: string): Promise<string | null> {
    return page.evaluate((fid) => {
        const el = document.querySelector(`select[data-wa-id="${fid}"]`) as HTMLSelectElement | null;
        const o = el?.selectedOptions[0];
        return o && o.value !== '' ? (o.textContent ?? '').replace(/\s+/g, ' ').trim() : null;
    }, id);
}

/** Index of the checked radio in the group, or -1. */
export async function readCheckedRadio(page: Page, id: string): Promise<number> {
    return page.evaluate((fid) => {
        const radios = [...document.querySelectorAll(`input[type="radio"][data-wa-id="${fid}"]`)] as HTMLInputElement[];
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

export async function observeFileInput(page: Page, id: string, expectedName: string): Promise<FileObservation> {
    return page.evaluate(
        async ([fid, expected]) => {
            const el = document.querySelector(`input[type="file"][data-wa-id="${fid}"]`) as HTMLInputElement | null;
            const file = el?.files?.[0];
            const nameInPageText = (document.body.innerText || '').includes(expected as string);
            if (!file) return { name: null, size: null, sha256: null, nameInPageText };
            let sha256: string | null = null;
            try {
                const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
                sha256 = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
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
    const text = await page.evaluate(() => document.body?.innerText ?? '').catch(() => '');
    const m = CONFIRMATION.exec(text);
    return { url: page.url(), confirmationText: m ? m[0] : null };
}
