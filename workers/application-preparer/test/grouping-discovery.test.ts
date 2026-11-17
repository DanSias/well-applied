// How discovery finds checkbox groups and their question labels, on a real DOM.
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
});
after(async () => {
    await browser.close();
    await server.close();
});

let n = 0;
async function discover(body: string) {
    const path = `/group-${n++}.html`;
    server.add(path, `<!doctype html><body><form>${body}</form></body>`);
    const page: Page = await browser.newPage();
    await page.goto(`${server.origin}${path}`);
    const d = await discoverForm(page);
    await page.close();
    return d.fields;
}
const boxes = (fs: Awaited<ReturnType<typeof discover>>) =>
    fs.filter((f) => f.kind === 'checkbox');

describe('checkbox groups', () => {
    it('shared name + fieldset/legend: one group, label from the legend', async () => {
        const f = boxes(
            await discover(`<fieldset><legend>Languages <span class="required">✱</span></legend>
          <label><input type="checkbox" name="lang" value="en" required> English</label>
          <label><input type="checkbox" name="lang" value="es"> Spanish</label></fieldset>`),
        );
        assert.equal(f.length, 2);
        assert.equal(f[0]!.group!.key, 'name:lang');
        assert.equal(f[1]!.group!.key, 'name:lang');
        assert.equal(f[0]!.group!.label, 'Languages');
        assert.equal(f[0]!.group!.marked, true);
        assert.deepEqual(
            f.map((x) => x.label),
            ['English', 'Spanish'],
        );
    });

    it('Lever-style: the question label is the .application-label of the container', async () => {
        const f = boxes(
            await discover(`<ul><li class="application-question"><label><div class="application-label">Language Skill(s) <span class="required">✱</span></div>
          <div class="application-field"><ul>
            <li><label><input type="checkbox" name="cards[a][field0]"> English (ENG)</label></li>
            <li><label><input type="checkbox" name="cards[a][field0]"> Spanish (SPA)</label></li>
            <li><label><input type="checkbox" name="cards[a][field0]"> French (FRA)</label></li>
          </ul></div></label></li></ul>`),
        );
        assert.equal(f.length, 3);
        assert.ok(f.every((x) => x.group?.key === 'name:cards[a][field0]'));
        assert.equal(f[0]!.group!.label, 'Language Skill(s)');
        assert.equal(f[0]!.group!.marked, true);
    });

    it('role=group with aria-labelledby gives the label', async () => {
        const f = boxes(
            await discover(`<div role="group" aria-labelledby="q1"><div id="q1">Pick all that apply</div>
          <label><input type="checkbox" name="p"> A</label><label><input type="checkbox" name="p"> B</label></div>`),
        );
        assert.equal(f[0]!.group!.label, 'Pick all that apply');
        assert.equal(f[0]!.group!.labelSource, 'dom_aria_labelledby');
    });

    it('a group with NO structural label has label null (no invention from the options)', async () => {
        const f = boxes(
            await discover(
                `<div><label><input type="checkbox" name="x"> No</label><label><input type="checkbox" name="x"> Yes - Intern</label></div>`,
            ),
        );
        assert.equal(f.length, 2);
        assert.equal(f[0]!.group!.key, 'name:x');
        assert.equal(f[0]!.group!.label, null);
        assert.equal(f[0]!.group!.labelSource, 'unresolved');
        assert.deepEqual(
            f.map((x) => x.label),
            ['No', 'Yes - Intern'],
        );
    });

    it('unnamed checkboxes in one fieldset form a container group', async () => {
        const f = boxes(
            await discover(
                `<fieldset><legend>Interests</legend><label><input type="checkbox"> Music</label><label><input type="checkbox"> Games</label></fieldset>`,
            ),
        );
        assert.equal(f.length, 2);
        assert.ok(f[0]!.group!.key.startsWith('container:'));
        assert.equal(f[0]!.group!.key, f[1]!.group!.key);
        assert.equal(f[0]!.group!.label, 'Interests');
    });

    it('two groups with different names stay separate', async () => {
        const f = boxes(
            await discover(`<fieldset><legend>One</legend><label><input type="checkbox" name="a"> a1</label><label><input type="checkbox" name="a"> a2</label></fieldset>
          <fieldset><legend>Two</legend><label><input type="checkbox" name="b"> b1</label><label><input type="checkbox" name="b"> b2</label></fieldset>`),
        );
        assert.deepEqual([...new Set(f.map((x) => x.group!.key))].sort(), [
            'name:a',
            'name:b',
        ]);
        assert.deepEqual([...new Set(f.map((x) => x.group!.label))].sort(), [
            'One',
            'Two',
        ]);
    });

    it('a single checkbox (e.g. a consent box) is not a group', async () => {
        const f = boxes(
            await discover(
                `<label><input type="checkbox" name="consent" required> I agree to the privacy policy</label>`,
            ),
        );
        assert.equal(f.length, 1);
        assert.equal(f[0]!.group, null);
        assert.equal(f[0]!.label, 'I agree to the privacy policy');
    });

    it('two unrelated unnamed checkboxes with no shared container are not grouped', async () => {
        const f = boxes(
            await discover(
                `<div><label><input type="checkbox"> Newsletter</label></div><div><label><input type="checkbox"> Updates</label></div>`,
            ),
        );
        assert.ok(f.every((x) => x.group === null));
    });

    it('records checked state', async () => {
        const f = boxes(
            await discover(
                `<fieldset><legend>Q</legend><label><input type="checkbox" name="q" checked> A</label><label><input type="checkbox" name="q"> B</label></fieldset>`,
            ),
        );
        assert.deepEqual(
            f.map((x) => x.checked),
            [true, false],
        );
    });
});

describe('selected values and label evidence', () => {
    it('records the option already selected in a radio group and in a select', async () => {
        const fs =
            await discover(`<fieldset><legend>Sure?</legend><label><input type="radio" name="s" value="y" checked> Yes</label><label><input type="radio" name="s" value="n"> No</label></fieldset>
          <label for="pick">Pick</label><select id="pick" name="pick"><option value="">Select</option><option selected>Two</option><option>Three</option></select>`);
        assert.deepEqual(fs.find((x) => x.kind === 'radio')!.selected, ['Yes']);
        assert.deepEqual(fs.find((x) => x.kind === 'select')!.selected, [
            'Two',
        ]);
    });

    it('a control with a real label keeps it (dom_label_for), never replaced by the weaker container fallback', async () => {
        const fs = await discover(
            `<ul><li><div class="application-label">Container text</div><label for="a">Real label</label><input id="a" name="a" type="text"></li></ul>`,
        );
        assert.equal(fs[0]!.label, 'Real label');
        assert.equal(fs[0]!.labelSource, 'dom_label_for');
    });

    it('a control with no label of its own falls back to the nearby question container, marked as inferred', async () => {
        const fs = await discover(
            `<ul><li><div class="application-label">What is your favourite colour?</div><div><input name="cards[q][field0]" type="text"></div></li></ul>`,
        );
        assert.equal(fs[0]!.label, 'What is your favourite colour?');
        assert.equal(fs[0]!.labelSource, 'inferred_proximity');
    });

    it('with no label and no container evidence the label stays unresolved', async () => {
        const fs = await discover(
            `<div><input name="cards[z][field0]" type="text"></div>`,
        );
        assert.equal(fs[0]!.label, null);
        assert.equal(fs[0]!.labelSource, 'unresolved');
    });
});
