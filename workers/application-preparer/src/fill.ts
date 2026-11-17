// The entire write surface of the worker: a closed set of four actions,
// each addressed by a discovery-assigned id (never a free-form selector)
// and each limited to one element type. There is deliberately no generic
// click, key press, or submit here; test/no-capability.test.ts enforces
// that nothing else in src/ gains one.
import { createHash } from 'node:crypto';
import type { Page } from 'playwright';

const ID = /^f\d+$/;
const STEP_TIMEOUT_MS = 5_000;

function target(id: string, elementSelector: string, extra = '') {
    if (!ID.test(id)) throw new Error(`invalid field id ${JSON.stringify(id)}`);
    return `${elementSelector}[data-wa-id="${id}"]${extra}`;
}

export async function fillText(
    page: Page,
    id: string,
    value: string,
): Promise<void> {
    await page
        .locator(`${target(id, 'input')}, ${target(id, 'textarea')}`)
        .first()
        .fill(value, { timeout: STEP_TIMEOUT_MS });
}

export async function selectOption(
    page: Page,
    id: string,
    optionLabel: string,
): Promise<void> {
    await page
        .locator(target(id, 'select'))
        .selectOption({ label: optionLabel }, { timeout: STEP_TIMEOUT_MS });
}

/** Picks one option of a radio group by its discovered index. */
export async function chooseRadio(
    page: Page,
    id: string,
    optionIndex: number,
): Promise<void> {
    if (!Number.isInteger(optionIndex) || optionIndex < 0)
        throw new Error('invalid radio option index');
    await page
        .locator(
            target(id, 'input[type="radio"]', `[data-wa-opt="${optionIndex}"]`),
        )
        .check({ timeout: STEP_TIMEOUT_MS });
}

/** The exact bytes handed to the browser, and their hash: never the path, which could change between reads. */
export interface ResumeArtifact {
    name: string;
    bytes: Buffer;
}

export async function uploadResumeFile(
    page: Page,
    id: string,
    file: ResumeArtifact,
): Promise<string> {
    await page.locator(target(id, 'input[type="file"]')).setInputFiles(
        {
            name: file.name,
            mimeType: 'application/pdf',
            buffer: file.bytes,
        },
        { timeout: STEP_TIMEOUT_MS },
    );
    return createHash('sha256').update(file.bytes).digest('hex');
}
