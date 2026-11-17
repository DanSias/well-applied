// Discovery against a real DOM that changes between calls.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, type Browser, type Page } from 'playwright';
import { discoverForm } from '../src/discover.ts';
import { startServer, type TestServer } from './support/server.ts';

let server: TestServer;
let browser: Browser;
before(async () => {
    server = await startServer();
    browser = await chromium.launch({ headless: true });
    server.add(
        '/identity.html',
        `<!doctype html><body><form id="f">
          <label for="first_name">First Name</label><input id="first_name" name="first_name" type="text">
          <div id="resume-wrap"><label for="resume">Resume</label><input id="resume" name="resume" type="file"></div>
          <div id="cover-wrap"><label for="cover_letter">Cover Letter</label><input id="cover_letter" name="cover_letter" type="file"></div>
          <fieldset><legend>Sponsorship</legend><label><input type="radio" name="spons" value="yes"> Yes</label><label><input type="radio" name="spons" value="no"> No</label></fieldset>
        </form></body>`,
    );
});
after(async () => {
    await browser.close();
    await server.close();
});

async function open(): Promise<Page> {
    const page = await browser.newPage();
    await page.goto(`${server.origin}/identity.html`);
    return page;
}
const byDomId = (
    fs: Awaited<ReturnType<typeof discoverForm>>['fields'],
    domId: string,
) => fs.find((f) => f.domId === domId);
const num = (id: string) => Number(id.slice(1));

describe('field ids are stable across DOM changes', () => {
    it('keeps every id when nothing changes', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const b = await discoverForm(page);
        assert.deepEqual(
            b.fields.map((f) => [f.id, f.key]),
            a.fields.map((f) => [f.id, f.key]),
        );
        await page.close();
    });

    it('INPUT DISAPPEARS: remaining fields keep their ids and the freed id is never handed to another element', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const resumeId = byDomId(a.fields, 'resume')!.id;
        const coverId = byDomId(a.fields, 'cover_letter')!.id;
        await page.evaluate(() =>
            document.getElementById('resume-wrap')!.remove(),
        );
        const b = await discoverForm(page);
        assert.equal(byDomId(b.fields, 'resume'), undefined);
        assert.equal(
            byDomId(b.fields, 'cover_letter')!.id,
            coverId,
            'the cover letter did not inherit the resume id',
        );
        assert.ok(
            !b.fields.some((f) => f.id === resumeId),
            'the removed id is gone',
        );
        await page.evaluate(() =>
            document
                .getElementById('f')!
                .insertAdjacentHTML(
                    'beforeend',
                    '<input id="late" name="late" type="text">',
                ),
        );
        const c = await discoverForm(page);
        assert.notEqual(
            byDomId(c.fields, 'late')!.id,
            resumeId,
            'a removed id is never reused',
        );
        await page.close();
    });

    it('FIELDS INSERTED: new fields get fresh ids, existing fields keep theirs, positions shift but identity does not', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const before = Object.fromEntries(
            a.fields.map((f) => [f.domId ?? f.name, f.id]),
        );
        const maxBefore = Math.max(...a.fields.map((f) => num(f.id)));
        await page.evaluate(() => {
            document
                .getElementById('f')!
                .insertAdjacentHTML(
                    'afterbegin',
                    '<input id="ins1" name="ins1" type="text"><input id="ins2" name="ins2" type="text">',
                );
            document
                .getElementById('resume-wrap')!
                .insertAdjacentHTML(
                    'beforebegin',
                    '<input id="ins3" name="ins3" type="text">',
                );
        });
        const b = await discoverForm(page);
        for (const f of a.fields) {
            const now = b.fields.find(
                (x) => (x.domId ?? x.name) === (f.domId ?? f.name),
            )!;
            assert.equal(now.id, f.id, `${f.domId ?? f.name} kept its id`);
        }
        const inserted = b.fields.filter((f) =>
            ['ins1', 'ins2', 'ins3'].includes(f.domId ?? ''),
        );
        assert.equal(inserted.length, 3);
        assert.ok(
            inserted.every((f) => num(f.id) > maxBefore),
            'new ids are fresh',
        );
        assert.notEqual(
            byDomId(b.fields, 'resume')!.position,
            byDomId(a.fields, 'resume')!.position,
            'its position did move',
        );
        assert.deepEqual(
            Object.fromEntries(
                b.fields
                    .filter((f) => before[f.domId ?? f.name ?? ''])
                    .map((f) => [f.domId ?? f.name, f.id]),
            ),
            before,
        );
        await page.close();
    });

    it('FIELDS REORDERED: ids follow the elements, not the order', async () => {
        const page = await open();
        const a = await discoverForm(page);
        await page.evaluate(() => {
            const f = document.getElementById('f')!;
            f.insertBefore(
                document.getElementById('cover-wrap')!,
                f.firstChild,
            );
        });
        const b = await discoverForm(page);
        assert.equal(
            byDomId(b.fields, 'cover_letter')!.id,
            byDomId(a.fields, 'cover_letter')!.id,
        );
        assert.equal(
            byDomId(b.fields, 'resume')!.id,
            byDomId(a.fields, 'resume')!.id,
        );
        assert.equal(b.fields[0]!.domId, 'cover_letter');
        await page.close();
    });

    it('RESUME AND COVER LETTER COEXIST: different keys, never confused', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const r = byDomId(a.fields, 'resume')!;
        const c = byDomId(a.fields, 'cover_letter')!;
        assert.notEqual(r.id, c.id);
        assert.notEqual(r.key, c.key);
        assert.equal(r.ambiguous, false);
        assert.equal(c.ambiguous, false);
        await page.close();
    });

    it('A FRAMEWORK RE-RENDER (new node, same identifying properties): new id, same key, not ambiguous', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const old = byDomId(a.fields, 'resume')!;
        await page.evaluate(() => {
            const w = document.getElementById('resume-wrap')!;
            w.innerHTML =
                '<label for="resume">Resume</label><input id="resume" name="resume" type="file">';
        });
        const b = await discoverForm(page);
        const now = byDomId(b.fields, 'resume')!;
        assert.notEqual(now.id, old.id);
        assert.equal(now.key, old.key);
        assert.equal(now.ambiguous, false);
        await page.close();
    });

    it('DUPLICATE IDENTIFIERS: a cloned node that copied data-wa-id gets its own id; look-alikes are flagged ambiguous', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const original = byDomId(a.fields, 'first_name')!;
        await page.evaluate(() => {
            const el = document.getElementById('first_name')!;
            const clone = el.cloneNode(true) as HTMLInputElement; // copies data-wa-id and the id attribute
            document.getElementById('f')!.appendChild(clone);
        });
        const b = await discoverForm(page);
        const twins = b.fields.filter((f) => f.domId === 'first_name');
        assert.equal(twins.length, 2);
        assert.notEqual(
            twins[0]!.id,
            twins[1]!.id,
            'no two elements share an id',
        );
        assert.equal(twins[0]!.id, original.id, 'the original keeps its id');
        assert.ok(
            twins.every((f) => f.ambiguous),
            'both are flagged as look-alikes',
        );
        assert.deepEqual(
            twins.map((f) => f.occurrence),
            [0, 1],
        );
        await page.close();
    });

    it('two inputs with no distinguishing properties at all are ambiguous by key but still have distinct ids', async () => {
        const page = await open();
        await page.evaluate(() => {
            document
                .getElementById('f')!
                .insertAdjacentHTML(
                    'beforeend',
                    '<input type="file"><input type="file">',
                );
        });
        const d = await discoverForm(page);
        const bare = d.fields.filter(
            (f) => f.kind === 'file' && !f.domId && !f.name,
        );
        assert.equal(bare.length, 2);
        assert.notEqual(bare[0]!.id, bare[1]!.id);
        assert.ok(bare.every((f) => f.ambiguous));
        await page.close();
    });

    it('a radio group keeps one stable id across discoveries and every option shares it', async () => {
        const page = await open();
        const a = await discoverForm(page);
        const g = a.fields.find((f) => f.kind === 'radio')!;
        const b = await discoverForm(page);
        assert.equal(b.fields.find((f) => f.kind === 'radio')!.id, g.id);
        assert.equal(
            await page
                .locator(`input[type="radio"][data-wa-id="${g.id}"]`)
                .count(),
            2,
        );
        await page.close();
    });
});
