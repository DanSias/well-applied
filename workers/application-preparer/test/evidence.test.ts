import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium, type Browser } from 'playwright';
import type { ScreenshotRecord } from '../src/contracts.ts';
import {
    captureViewports,
    coverageWarnings,
    planScrollPositions,
} from '../src/evidence.ts';
import { startServer, type TestServer } from './support/server.ts';
import { fixtureHtml, LEVER_AUTH_QUESTION, runOn } from './support/run.ts';

let server: TestServer;
let browser: Browser;
before(async () => {
    server = await startServer();
    browser = await chromium.launch({ headless: true });
});
after(async () => {
    await browser.close();
    await server.close();
});

const STICKY = `
<style>
  .hdr { position: sticky; top: 0; height: 90px; background: #fff; z-index: 10; border-bottom: 1px solid #ccc }
  .banner { position: fixed; left: 0; right: 0; bottom: 0; height: 60px; background: #222; color: #fff; z-index: 10 }
</style>`;

describe('screenshot planning (pure)', () => {
    it('plans overlapping positions that end exactly at the bottom of the page', () => {
        const { positions, truncated } = planScrollPositions(
            6973,
            1000,
            90,
            60,
        );
        assert.equal(truncated, false);
        assert.equal(positions[0], 0);
        assert.equal(positions.at(-1), 6973 - 1000);
        const step = positions[1]! - positions[0]!;
        assert.ok(
            step <= 1000 - 90 - 60,
            `step ${step} must leave room for the overlays`,
        );
    });

    it('a short page is one shot', () => {
        assert.deepEqual(planScrollPositions(800, 1000, 90, 60), {
            positions: [0],
            truncated: false,
        });
    });

    it('caps the number of shots and says so', () => {
        const { positions, truncated } = planScrollPositions(
            200_000,
            1000,
            0,
            0,
        );
        assert.equal(positions.length, 16);
        assert.equal(truncated, true);
    });

    it('flags rows that an overlay would hide in both neighbouring shots', () => {
        const rec = (index: number, scrollY: number): ScreenshotRecord => ({
            file: `${index}.png`,
            phase: 'before',
            index,
            of: 2,
            scrollY,
            viewportWidth: 1400,
            viewportHeight: 1000,
            documentHeight: 2000,
            // a 900px fixed overlay hides almost the whole viewport at the top
            fixedOverlays: [
                {
                    tag: 'div',
                    id: null,
                    className: 'huge',
                    position: 'fixed',
                    top: 0,
                    height: 900,
                    anchored: 'top',
                },
            ],
        });
        const warnings = coverageWarnings('before', [rec(1, 0), rec(2, 1000)]);
        assert.match(
            warnings.join(' '),
            /may be hidden behind a sticky\/fixed element/,
        );
    });

    it('is satisfied by the plan for realistic overlays', () => {
        const { positions } = planScrollPositions(6973, 1000, 90, 60);
        const records = positions.map((y, i): ScreenshotRecord => ({
            file: `${i}.png`,
            phase: 'before',
            index: i + 1,
            of: positions.length,
            scrollY: y,
            viewportWidth: 1400,
            viewportHeight: 1000,
            documentHeight: 6973,
            fixedOverlays: [
                {
                    tag: 'header',
                    id: null,
                    className: 'hdr',
                    position: 'sticky',
                    top: 0,
                    height: 90,
                    anchored: 'top',
                },
                {
                    tag: 'div',
                    id: null,
                    className: 'banner',
                    position: 'fixed',
                    top: 940,
                    height: 60,
                    anchored: 'bottom',
                },
            ],
        }));
        assert.deepEqual(coverageWarnings('before', records), []);
    });
});

describe('screenshots of a page with a sticky header and a cookie banner', () => {
    it('captures viewport-sized shots, records position and overlays, and leaves the page untouched', async () => {
        const html = `<!doctype html><html><head>${STICKY}</head><body>
          <header class="hdr">Sticky header</header>
          ${Array.from({ length: 40 }, (_, i) => `<p style="height:150px;margin:0">row ${i}</p>`).join('')}
          <div class="banner">Cookie banner</div></body></html>`;
        server.add('/long-sticky.html', html);
        const context = await browser.newContext({
            viewport: { width: 1400, height: 1000 },
        });
        const page = await context.newPage();
        await page.goto(`${server.origin}/long-sticky.html`);
        const before = await page.evaluate(
            () => document.documentElement.outerHTML,
        );
        const dir = await mkdtemp(join(tmpdir(), 'preparer-shots-'));

        const { records, warnings } = await captureViewports(
            page,
            dir,
            'before',
        );

        assert.ok(
            records.length >= 6,
            `expected several shots, got ${records.length}`,
        );
        assert.deepEqual(
            records.map((r) => r.index),
            records.map((_, i) => i + 1),
        );
        assert.ok(
            records.every(
                (r) =>
                    r.of === records.length &&
                    r.phase === 'before' &&
                    r.viewportHeight === 1000,
            ),
        );
        assert.ok(
            records.every(
                (r, i) => i === 0 || r.scrollY > records[i - 1]!.scrollY,
            ),
            'scroll positions strictly increase',
        );
        const last = records.at(-1)!;
        assert.ok(
            last.scrollY + last.viewportHeight >= last.documentHeight - 1,
            'the bottom of the page is covered',
        );

        assert.ok(
            records.some((r) =>
                r.fixedOverlays.some(
                    (o) => o.anchored === 'top' && o.position === 'sticky',
                ),
            ),
            'sticky header recorded',
        );
        assert.ok(
            records.some((r) =>
                r.fixedOverlays.some(
                    (o) => o.anchored === 'bottom' && o.position === 'fixed',
                ),
            ),
            'cookie banner recorded',
        );
        assert.deepEqual(
            warnings,
            [],
            'the overlap plan leaves no row hidden in every shot',
        );
        assert.deepEqual(coverageWarnings('before', records), []);

        for (const r of records) {
            const png = await readFile(join(dir, r.file));
            assert.equal(png.readUInt32BE(16), 1400, `${r.file} width`);
            assert.equal(
                png.readUInt32BE(20),
                1000,
                `${r.file} is viewport-sized, not full-page`,
            );
            assert.match(r.file, /^before-\d\dof\d\d-y\d{5}\.png$/);
        }

        assert.equal(
            await page.evaluate(() => window.scrollY),
            0,
            'scroll restored',
        );
        assert.equal(
            await page.evaluate(() => document.documentElement.outerHTML),
            before,
            'the page DOM and styling were not modified',
        );
        await context.close();
    });

    it('through the engine: the report identifies each shot and the overlays on screen, with no obscuring warning', async () => {
        const base = await fixtureHtml('lever-form.html');
        const html = base
            .replace('</head>', `${STICKY}</head>`)
            .replace(
                /<body([^>]*)>/,
                '<body$1><header class="hdr">Sticky header</header>',
            )
            .replace(
                '</body>',
                '<div style="height:2500px">spacer</div><div class="banner">We use cookies</div></body>',
            );
        server.add('/lever-sticky.html', html);
        const { report } = await runOn(server, '/lever-sticky.html', 'lever', {
            input: { answers: { [LEVER_AUTH_QUESTION]: 'Yes' } },
        });
        for (const phase of ['before', 'after_fill'] as const) {
            const shots = report.screenshots.filter((s) => s.phase === phase);
            assert.ok(shots.length >= 3, `${phase}: ${shots.length} shots`);
            assert.deepEqual(
                shots.map((s) => s.index),
                shots.map((_, i) => i + 1),
            );
            assert.ok(shots.every((s) => s.of === shots.length));
            assert.ok(
                shots.some((s) =>
                    s.fixedOverlays.some((o) => o.anchored === 'bottom'),
                ),
                'banner recorded',
            );
        }
        assert.equal(
            report.warnings.some((w) =>
                /hidden behind|below the last shot/.test(w),
            ),
            false,
            JSON.stringify(report.warnings),
        );
    });
});
