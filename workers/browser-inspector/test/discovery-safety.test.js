// M1.3: discovery cannot interact with a page, cannot read what a person entered, cannot reach beyond the page, and cannot be
// given a way to. Enforced three ways: the source of src/discovery is scanned, the page handle refuses unapproved functions, and
// discovery is run over the pages that are built to punish any activation. Offline.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';
import { loadCorpus, runOnFixture } from './support/discovery-score.js';
import { discoverForm } from '../src/discovery/index.js';
import { readOnlyPage } from '../src/discovery/page-adapter.js';
import { collectSignals } from '../src/discovery/collect.js';

const DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src', 'discovery');
const files = readdirSync(DIR).filter((f) => f.endsWith('.js'));
const code = (f) => readFileSync(path.join(DIR, f), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
const corpus = loadCorpus();

describe('the discovery source cannot interact with a page', () => {
    // Anything that acts on a page, a pointer or a keyboard, or that dispatches an event, navigates, or opens a browser.
    const ACTIONS = /\.(click|dblclick|fill|type|press|check|uncheck|selectOption|setInputFiles|hover|focus|blur|tap|dragTo|dispatchEvent|submit|requestSubmit|reset|scrollIntoView|scrollTo|goto|reload|goBack|goForward|route|launch|newPage|newContext|keyboard|mouse|touchscreen)\b\s*\(/;

    test('no module calls an interaction, navigation or event-dispatch API', () => {
        for (const f of files) assert.ok(!ACTIONS.test(code(f)), `${f} contains an action`);
    });

    test('no module reads a control\'s entered value or state', () => {
        for (const f of files) {
            const src = code(f);
            assert.ok(!/\b(el|input|node|control|candidate)\.(value|checked|selected|selectedIndex|files|valueAsNumber)\b/.test(src), `${f} reads an entered value`);
            assert.ok(!/\.(inputValue|isChecked|getAttribute\(['"]value['"]\))/.test(src), `${f} reads an entered value`);
        }
    });

    test('only the page adapter mentions a Page, and only to evaluate and to read the accessibility snapshot', () => {
        for (const f of files.filter((x) => x !== 'page-adapter.js')) assert.ok(!/\bpage\./.test(code(f)), `${f} touches a page`);
        const calls = [...code('page-adapter.js').matchAll(/\bpage\.(\w+)/g)].map((m) => m[1]).sort();
        assert.deepEqual([...new Set(calls)], ['evaluate', 'locator', 'url']);
        assert.ok(/page\.locator\('body'\)\.ariaSnapshot\(\)/.test(code('page-adapter.js')));
    });

    test('the collector tags nothing, listens to nothing and never changes the page', () => {
        const src = code('collect.js');
        assert.ok(!/\.(setAttribute|removeAttribute|appendChild|insertBefore|remove\b|innerHTML\s*=|textContent\s*=|classList\.(add|remove|toggle)|addEventListener|style\.)/.test(src.replace(/clone\.querySelectorAll[\s\S]*?\.remove\(\)/g, '').replace(/n\.remove\(\)/g, '')), 'collect.js writes to the page');
        // The only .remove() calls are on a detached CLONE of a label, to read its text without form controls in it.
        assert.equal((src.match(/\.remove\(\)/g) ?? []).length, 2);
        assert.ok(src.includes('const clone = el.cloneNode(true)'));
    });

    test('discovery never launches or configures a browser', () => {
        for (const f of files) assert.ok(!/chromium|playwright|launch\(/.test(code(f).replace(/page-adapter/g, '')) || f === 'page-adapter.js', f);
    });
});

describe('the page handle only lets the approved reader run', () => {
    let browser;
    before(async () => {
        browser = await chromium.launch({ headless: true, chromiumSandbox: process.env.BROWSER_WORKER_CHROMIUM_SANDBOX === '1' });
    });
    after(async () => {
        await browser?.close();
    });

    test('a function that would click is refused before it reaches the page', async () => {
        const page = await browser.newPage();
        await page.setContent('<button id="b" onclick="window.fired=(window.fired||0)+1">Apply</button>');
        const handle = readOnlyPage(page);
        await assert.rejects(handle.evaluate(() => document.getElementById('b').click()), /not an approved reader/);
        await assert.rejects(handle.evaluate(() => (window.x = 1)), /not an approved reader/);
        assert.equal(await page.evaluate(() => window.fired ?? 0), 0);
        const signals = await handle.evaluate(collectSignals);
        assert.equal(signals.candidates.length, 0, 'the approved reader still works');
        await page.close();
    });

    test('the handle exposes exactly url, evaluate and ariaNames: no Page, no locator, no keyboard', async () => {
        const page = await browser.newPage();
        const handle = readOnlyPage(page);
        assert.deepEqual(Object.keys(handle).sort(), ['ariaNames', 'evaluate', 'url']);
        await page.close();
    });

    test('discovery over the trap pages activates nothing, requests nothing and changes nothing', async () => {
        for (const id of ['apply-button-nonget', 'auth-gate-aggregator', 'multi-step', 'conditional-hidden-fields']) {
            const fixture = corpus.fixtures.find((f) => f.id === id);
            const run = await runOnFixture(browser, fixture, (page) => discoverForm(readOnlyPage(page)));
            assert.deepEqual(run.safety.requests, [], `${id} made requests`);
            assert.equal(run.safety.domChanged, false, `${id} was modified`);
            assert.ok([null, 0].includes(run.safety.trapFired), `${id}: a trap fired`);
            await run.close();
        }
    });

    test('the page\'s own scripts, handlers and revealed content are untouched: step 3 and the province field still do not exist', async () => {
        for (const [id, absent] of [['multi-step', '#step-3'], ['conditional-hidden-fields', '#province']]) {
            const fixture = corpus.fixtures.find((f) => f.id === id);
            const run = await runOnFixture(browser, fixture, (page) => discoverForm(readOnlyPage(page)));
            assert.equal(await run.page.locator(absent).count(), 0, `${id}: ${absent} appeared`);
            await run.close();
        }
    });

    test('the ariaNames capability is read-only and lower-cases quoted names', async () => {
        const page = await browser.newPage();
        await page.setContent('<label for="a">First Name</label><input id="a">');
        const names = await readOnlyPage(page).ariaNames();
        assert.ok(names.has('first name'));
        await page.close();
    });
});
