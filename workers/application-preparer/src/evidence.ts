// Evidence on disk: screenshots and the JSON report. Everything lands in
// runs/<runId>/, which is gitignored: it contains applicant data.
//
// Screenshots are viewport-sized captures at scroll positions chosen so
// consecutive shots overlap by more than the sticky/fixed overlays measured
// on the page (header, cookie banner): any content hidden behind an overlay
// in one shot is visible in a neighbouring one. Nothing on the page is
// restyled, hidden or dismissed; only the scroll position changes, and it
// is restored afterwards. Each record says which shot it is, where it was
// taken, and which overlays were on screen.
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { Page } from 'playwright';
import type {
    FixedOverlay,
    PrepareReport,
    ScreenshotPhase,
    ScreenshotRecord,
} from './contracts.ts';

const MAX_SHOTS = 16;
const MIN_STEP = 300;
const MARGIN = 40;

export async function createRunDir(
    runsDir: string,
    runId: string,
): Promise<string> {
    const dir = join(runsDir, runId);
    // Not recursive on the final segment: refuses to reuse an existing run.
    await mkdir(runsDir, { recursive: true });
    await mkdir(dir);
    return dir;
}

interface Metrics {
    scrollY: number;
    viewportWidth: number;
    viewportHeight: number;
    documentHeight: number;
    overlays: FixedOverlay[];
}

/** Read-only measurement of the current scroll position and fixed/sticky overlays. */
async function measure(page: Page): Promise<Metrics> {
    return page.evaluate(() => {
        const vh = window.innerHeight;
        const vw = window.innerWidth;
        const overlays: FixedOverlay[] = [];
        let seen = 0;
        for (const el of document.querySelectorAll('body *')) {
            if (++seen > 5000) break;
            const cs = getComputedStyle(el);
            if (cs.position !== 'fixed' && cs.position !== 'sticky') continue;
            if (
                !el.checkVisibility({
                    checkOpacity: true,
                    checkVisibilityCSS: true,
                })
            )
                continue;
            const r = el.getBoundingClientRect();
            if (r.height < 8 || r.width < vw * 0.2) continue;
            if (r.bottom <= 0 || r.top >= vh) continue;
            overlays.push({
                tag: el.tagName.toLowerCase(),
                id: el.id || null,
                className:
                    (el.className?.toString?.() ?? '').split(/\s+/)[0] || null,
                position: cs.position as 'fixed' | 'sticky',
                top: Math.round(r.top),
                height: Math.round(r.height),
                anchored:
                    r.top <= 1
                        ? 'top'
                        : r.bottom >= vh - 1
                          ? 'bottom'
                          : 'floating',
            });
        }
        return {
            scrollY: Math.round(window.scrollY),
            viewportWidth: vw,
            viewportHeight: vh,
            documentHeight: Math.max(
                document.documentElement.scrollHeight,
                document.body?.scrollHeight ?? 0,
            ),
            overlays,
        };
    });
}

async function scrollTo(page: Page, y: number): Promise<void> {
    await page.evaluate((top) => {
        window.scrollTo({ top, behavior: 'instant' as ScrollBehavior });
    }, y);
    await page.waitForTimeout(150); // let sticky elements and lazy content paint
}

const topInset = (o: FixedOverlay[]) =>
    Math.max(
        0,
        ...o.filter((x) => x.anchored === 'top').map((x) => x.top + x.height),
    );
const bottomInset = (o: FixedOverlay[], vh: number) =>
    Math.max(
        0,
        ...o.filter((x) => x.anchored === 'bottom').map((x) => vh - x.top),
    );

/**
 * Pure: the scroll positions to capture, given the document and the overlay
 * insets (px hidden at the top and bottom of every viewport).
 */
export function planScrollPositions(
    documentHeight: number,
    viewportHeight: number,
    topPx: number,
    bottomPx: number,
): { positions: number[]; truncated: boolean } {
    const maxScroll = Math.max(0, documentHeight - viewportHeight);
    // Next shot's first visible row must be <= previous shot's last visible row.
    const step = Math.max(MIN_STEP, viewportHeight - topPx - bottomPx - MARGIN);
    const positions: number[] = [];
    for (let y = 0; y < maxScroll; y += step) positions.push(y);
    positions.push(maxScroll);
    if (positions.length <= MAX_SHOTS) return { positions, truncated: false };
    return { positions: positions.slice(0, MAX_SHOTS), truncated: true };
}

export async function captureViewports(
    page: Page,
    dir: string,
    phase: ScreenshotPhase,
): Promise<{ records: ScreenshotRecord[]; warnings: string[] }> {
    const warnings: string[] = [];
    try {
        await scrollTo(page, 0);
        const first = await measure(page);
        // Probe a scrolled position too: sticky headers may only engage once scrolled.
        const probeY = Math.min(
            MIN_STEP,
            Math.max(0, first.documentHeight - first.viewportHeight),
        );
        await scrollTo(page, probeY);
        const probe = await measure(page);
        const top = Math.max(
            topInset(first.overlays),
            topInset(probe.overlays),
        );
        const bottom = Math.max(
            bottomInset(first.overlays, first.viewportHeight),
            bottomInset(probe.overlays, probe.viewportHeight),
        );

        const { positions, truncated } = planScrollPositions(
            first.documentHeight,
            first.viewportHeight,
            top,
            bottom,
        );
        if (truncated)
            warnings.push(
                `${phase}: page is longer than ${MAX_SHOTS} viewport shots; the lower part was not captured`,
            );

        const records: ScreenshotRecord[] = [];
        for (const [i, y] of positions.entries()) {
            await scrollTo(page, y);
            const m = await measure(page);
            const name = `${phase}-${String(i + 1).padStart(2, '0')}of${String(positions.length).padStart(2, '0')}-y${String(m.scrollY).padStart(5, '0')}.png`;
            try {
                await page.screenshot({
                    path: join(dir, name),
                    fullPage: false,
                    timeout: 15_000,
                });
            } catch {
                warnings.push(`${phase}: screenshot ${i + 1} failed`);
                continue;
            }
            records.push({
                file: name,
                phase,
                index: i + 1,
                of: positions.length,
                scrollY: m.scrollY,
                viewportWidth: m.viewportWidth,
                viewportHeight: m.viewportHeight,
                documentHeight: m.documentHeight,
                fixedOverlays: m.overlays,
            });
        }
        await scrollTo(page, 0);
        warnings.push(...coverageWarnings(phase, records));
        return { records, warnings };
    } catch (e) {
        warnings.push(
            `${phase}: screenshots failed: ${String((e as Error).message).split('\n')[0]}`,
        );
        return { records: [], warnings };
    }
}

/**
 * Pure: checks the coverage invariant from the recorded measurements, so a
 * page whose overlays defeat the plan is reported rather than silently
 * accepted. Every row between the first and last shot must be unobscured in
 * at least one shot.
 */
export function coverageWarnings(
    phase: ScreenshotPhase,
    records: ScreenshotRecord[],
): string[] {
    const out: string[] = [];
    for (let i = 1; i < records.length; i++) {
        const prev = records[i - 1]!;
        const next = records[i]!;
        const prevLastVisible =
            prev.scrollY +
            prev.viewportHeight -
            bottomInset(prev.fixedOverlays, prev.viewportHeight);
        const nextFirstVisible = next.scrollY + topInset(next.fixedOverlays);
        if (nextFirstVisible > prevLastVisible)
            out.push(
                `${phase}: rows ${prevLastVisible}-${nextFirstVisible} may be hidden behind a sticky/fixed element in shots ${prev.index} and ${next.index}`,
            );
    }
    const last = records.at(-1);
    if (last && last.scrollY + last.viewportHeight < last.documentHeight - 1)
        out.push(`${phase}: the bottom of the page is below the last shot`);
    return out;
}

export async function writeReport(
    dir: string,
    report: PrepareReport,
): Promise<string> {
    const path = join(dir, 'report.json');
    await writeFile(path, JSON.stringify(report, null, 2) + '\n', {
        mode: 0o600,
    });
    return path;
}
