// Resume-parser status: what the page VISIBLY says, kept strictly apart from
// "the file was attached and hashed" and "the parse request was allowed".
// Success is only ever claimed from a visible success indicator.
import type { Page } from 'playwright';
import type { ResumeParserReport, ResumeParserStatus } from './contracts.ts';
import type { ResumeParserProbe } from './adapters/index.ts';
import { readParserObservation, type ParserObservation } from './verify.ts';

const POLL_MS = 300;

/** Pure: turns the last observation into a report status. Exported for tests. */
export function judgeParser(args: {
    observation: ParserObservation;
    requestAllowed: boolean;
    waitedMs: number;
    /** True when the wait ended on a terminal state; false when it ran out. */
    terminal: boolean;
    attached: boolean;
}): { status: ResumeParserStatus; detail: string } {
    const { observation, requestAllowed, waitedMs, terminal, attached } = args;
    if (!attached)
        return { status: 'not_attempted', detail: 'no resume was attached' };
    if (observation === 'success')
        return {
            status: 'success',
            detail: 'a visible success indicator is displayed',
        };
    if (observation === 'failure')
        return {
            status: 'failure',
            detail: 'a visible failure indicator is displayed',
        };
    if (observation === 'conflict')
        return {
            status: 'unknown',
            detail: 'success and failure indicators are both visible; neither is claimed',
        };
    if (!terminal && observation === 'working')
        return {
            status: 'unknown',
            detail: `still analyzing after ${waitedMs}ms; neither success nor failure is claimed`,
        };
    if (!requestAllowed)
        return {
            status: 'not_attempted',
            detail: 'no parse request was allowed and no parser indicator appeared',
        };
    return {
        status: 'unknown',
        detail: `the parse request was allowed but no parser indicator became visible within ${waitedMs}ms`,
    };
}

/**
 * Observes the parser for at most `timeoutMs`, returning as soon as a
 * terminal indicator (success, failure, conflicting) is visible. Never
 * waits indefinitely and never clicks anything.
 */
export async function observeParser(
    page: Page,
    probe: ResumeParserProbe,
    ctx: {
        requestAllowed: () => boolean;
        attached: boolean;
        timeoutMs: number;
    },
): Promise<Pick<ResumeParserReport, 'status' | 'detail' | 'waitedMs'>> {
    const started = Date.now();
    let observation: ParserObservation = 'none';
    let terminal = false;
    for (;;) {
        observation = await readParserObservation(page, probe);
        terminal =
            observation === 'success' ||
            observation === 'failure' ||
            observation === 'conflict';
        const waited = Date.now() - started;
        // Nothing allowed and nothing showing: there is nothing to wait for.
        const idle = observation === 'none' && !ctx.requestAllowed();
        if (terminal || idle || waited >= ctx.timeoutMs || !ctx.attached) break;
        await page.waitForTimeout(POLL_MS);
    }
    const waitedMs = Date.now() - started;
    return {
        ...judgeParser({
            observation,
            requestAllowed: ctx.requestAllowed(),
            waitedMs,
            terminal,
            attached: ctx.attached,
        }),
        waitedMs,
    };
}
