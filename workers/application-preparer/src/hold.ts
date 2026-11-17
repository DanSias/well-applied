// The review-hold lifecycle: how a held (headed) session ends.
//
// A hold keeps Chromium open for a person, with the submission guard still
// fully active. It ends on whichever comes first:
//   - Enter pressed in an interactive terminal            ('enter')
//   - the STOP signal file appears in the run directory   ('stop_file')
//   - the browser window is closed                        ('window_closed')
//   - the browser process disconnects                     ('browser_disconnected')
//
// The STOP file is ONLY checked for existence. Its contents are never read,
// parsed or acted on, so it cannot carry an instruction: it can end the
// hold and nothing else. It does not authorize any browser action, does not
// touch the guard, and cannot trigger a submission.
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import type { Browser, Page } from 'playwright';
import type { HoldEnd } from './contracts.ts';

export const STOP_FILE_NAME = 'STOP';

export function stopFilePath(runDir: string): string {
    return join(runDir, STOP_FILE_NAME);
}

/** The few stdin members used, so tests can inject a stand-in. */
export interface HoldStdin {
    isTTY?: boolean;
    once(event: 'data', listener: () => void): unknown;
    removeListener(event: 'data', listener: () => void): unknown;
    pause?(): unknown;
}

export interface HoldSignals {
    stopFile: string;
    stdin?: HoldStdin;
    /** How often the STOP file is looked for. */
    pollMs?: number;
}

export function waitForHoldEnd(
    page: Page,
    browser: Browser,
    { stopFile, stdin = process.stdin, pollMs = 500 }: HoldSignals,
): Promise<HoldEnd> {
    return new Promise((resolve) => {
        let settled = false;
        const cleanup: Array<() => void> = [];
        const end = (reason: HoldEnd) => {
            if (settled) return;
            settled = true;
            for (const c of cleanup) c();
            resolve(reason);
        };

        const onPageClose = () => end('window_closed');
        const onDisconnect = () => end('browser_disconnected');
        page.once('close', onPageClose);
        browser.once('disconnected', onDisconnect);
        cleanup.push(
            () => page.off('close', onPageClose),
            () => browser.off('disconnected', onDisconnect),
        );

        if (stdin.isTTY) {
            const onEnter = () => end('enter');
            stdin.once('data', onEnter);
            cleanup.push(() => {
                stdin.removeListener('data', onEnter);
                stdin.pause?.();
            });
        }

        // Existence only. Never read the file.
        const check = () => {
            if (existsSync(stopFile)) end('stop_file');
        };
        check();
        if (!settled) {
            const timer = setInterval(check, pollMs);
            cleanup.push(() => clearInterval(timer));
        }
    });
}
