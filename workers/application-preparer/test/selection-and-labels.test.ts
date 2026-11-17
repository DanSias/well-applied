// Two discovery questions on a real DOM, then the engine:
//  1. Does a combobox-style control (react-select, a phone-country picker) show a REAL selected
//     value, or only a placeholder / decoration? Only a rendered selected value counts, and even
//     then the worker neither chose nor interprets it.
//  2. Which heading belongs to a file control whose own label is only its button ("Attach")?
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chromium, type Browser, type Page } from 'playwright';
import { discoverForm } from '../src/discover.ts';
import { fixtureHtml, runOn } from './support/run.ts';
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
async function discover(body: string, page?: Page) {
    const path = `/sel-${n++}.html`;
    server.add(path, `<!doctype html><body><form>${body}</form></body>`);
    const p: Page = page ?? (await browser.newPage());
    await p.goto(`${server.origin}${path}`);
    const d = await discoverForm(p);
    if (!page) await p.close();
    return d.fields;
}

const combo = (inner: string, extra = '') => `
  <label for="country">Country</label>
  <div class="select__control"><div class="select__value-container">
    ${inner}
    <input id="country" type="text" role="combobox" aria-haspopup="listbox" aria-expanded="false" autocomplete="off" ${extra}>
  </div></div>`;

describe('combobox selection: a real value versus a placeholder or decoration', () => {
    it('a rendered selected value (react-select single value) is "selected"', async () => {
        const [f] = await discover(
            combo('<div class="select__single-value">+1</div>'),
        );
        assert.equal(f!.selection?.state, 'selected');
        assert.equal(f!.selection?.text, '+1');
    });

    it('a placeholder is not a selection', async () => {
        const [f] = await discover(
            combo('<div class="select__placeholder">Select...</div>'),
        );
        assert.equal(f!.selection?.state, 'placeholder');
        assert.equal(f!.selection?.text, null);
    });

    it('placeholder-looking text rendered in the value slot is not a selection', async () => {
        for (const text of ['Select...', 'Choose one', 'Search']) {
            const [f] = await discover(
                combo(`<div class="select__single-value">${text}</div>`),
            );
            assert.equal(f!.selection?.state, 'placeholder', text);
        }
    });

    it('an empty value slot is not a selection', async () => {
        const [f] = await discover(
            combo('<div class="select__single-value">  </div>'),
        );
        assert.notEqual(f!.selection?.state, 'selected');
    });

    it('a hidden value slot (display:none) is not a selection', async () => {
        const [f] = await discover(
            combo(
                '<div class="select__single-value" style="display:none">+1</div>',
            ),
        );
        assert.notEqual(f!.selection?.state, 'selected');
    });

    it('a dialing code shown as decoration outside the control is not a selection', async () => {
        const [f] = await discover(`
          <span class="flag-decoration">+1</span>
          <input id="country" type="text" role="combobox" aria-haspopup="listbox" autocomplete="off">`);
        assert.equal(f!.selection?.state, 'none');
    });

    it('an ordinary text input (no combobox semantics) is never read for a selection', async () => {
        const [f] = await discover(
            `<div class="select__single-value">+1</div><input id="x" type="text">`,
        );
        assert.equal(f!.selection ?? null, null);
    });

    it('a typed value in the search input is not a selection', async () => {
        const [f] = await discover(
            combo(
                '<div class="select__placeholder">Select...</div>',
                'value="Uni"',
            ),
        );
        assert.equal(f!.selection?.state, 'placeholder');
    });
});

describe('Greenhouse phone-country selector through the engine', () => {
    const base = async () =>
        (await fixtureHtml('greenhouse-upload.html')).replace(
            '<div class="field upload" data-field="resume">',
            `<div class="field" id="country-field">__COUNTRY__</div>
             <div class="field upload" data-field="resume">`,
        );
    const withCountry = async (path: string, inner: string) => {
        server.add(
            path,
            (await base()).replace(
                '__COUNTRY__',
                combo(inner).replace(
                    '<label for="country">Country</label>',
                    '<label for="country">Country <span>*</span></label>',
                ),
            ),
        );
        return runOn(server, path, 'greenhouse', {
            options: {
                parserWaitMs: 200,
                uploadWaitMs: 800,
                uploadConfirmWaitMs: 300,
            },
        });
    };
    const country = (r: Awaited<ReturnType<typeof withCountry>>) =>
        r.report.questions.find((q) => q.label === 'Country')!;

    it('a displayed +1 is reported as preselected by the page: not unanswered, not completed, not interpreted', async () => {
        const r = await withCountry(
            '/gh-country-selected.html',
            '<div class="select__single-value">+1</div>',
        );
        const q = country(r);
        assert.equal(q.status, 'preselected_by_page');
        assert.equal(q.review, 'needs_check');
        assert.deepEqual(q.provenance, { source: 'none' });
        assert.equal(q.expected, undefined);
        assert.match(q.note ?? '', /neither chosen nor interpreted/);
        assert.equal(r.report.summary.preselectedByPage, 1);
        assert.equal(r.report.summary.unresolvedRequired, 0);
        assert.ok(r.report.questionSummary.needsCheck >= 1);
        // Nothing about the dialing code leaks into the applicant's facts.
        assert.equal(
            r.report.fields.filter((f) => f.mappedTo === 'location').length,
            0,
        );
    });

    it('a placeholder is still an unanswered required question', async () => {
        const r = await withCountry(
            '/gh-country-placeholder.html',
            '<div class="select__placeholder">Select...</div>',
        );
        const q = country(r);
        assert.equal(q.status, 'unresolved_required');
        assert.equal(q.review, 'needs_answer');
        assert.ok(r.report.questionSummary.needsAnswer >= 1);
    });

    it('decoration without a selected-value element is still unanswered', async () => {
        const r = await withCountry(
            '/gh-country-decoration.html',
            '<span class="dial">+1</span>',
        );
        assert.equal(country(r).status, 'unresolved_required');
    });
});

describe('file control labels: the question heading, not the button text', () => {
    const fileLabel = (fs: Awaited<ReturnType<typeof discover>>, id: string) =>
        fs.find((f) => f.domId === id)!;

    it('Greenhouse-style: <label for> holds only "Attach"; the heading above gives Resume/CV and Cover Letter', async () => {
        const fs = await discover(`
          <div class="file-upload" data-field="resume">
            <div class="upload-label label">Resume/CV <span>*</span></div>
            <label for="resume">Attach</label><input id="resume" type="file">
            <button type="button">Dropbox</button><button type="button">Google Drive</button>
          </div>
          <div class="file-upload" data-field="cover_letter">
            <div class="upload-label label">Cover Letter</div>
            <label for="cover_letter">Attach</label><input id="cover_letter" type="file">
          </div>`);
        const resume = fileLabel(fs, 'resume');
        const cover = fileLabel(fs, 'cover_letter');
        assert.equal(resume.label, 'Resume/CV');
        assert.equal(cover.label, 'Cover Letter');
        assert.equal(resume.labelSource, 'inferred_proximity');
        assert.notEqual(resume.key, cover.key);
        assert.equal(resume.required, true);
        assert.equal(resume.ambiguous, false);
    });

    it('a role=group with aria-labelledby is structural evidence', async () => {
        const fs = await discover(`
          <div role="group" aria-labelledby="lbl-r"><span id="lbl-r">Resume/CV</span>
            <label for="resume">Attach</label><input id="resume" type="file"></div>
          <div role="group" aria-labelledby="lbl-c"><span id="lbl-c">Cover Letter</span>
            <label for="cover_letter">Attach</label><input id="cover_letter" type="file"></div>`);
        assert.equal(fileLabel(fs, 'resume').label, 'Resume/CV');
        assert.equal(
            fileLabel(fs, 'resume').labelSource,
            'dom_aria_labelledby',
        );
        assert.equal(fileLabel(fs, 'cover_letter').label, 'Cover Letter');
    });

    it('two uploads sharing one container: no heading is guessed, both keep their own label', async () => {
        const fs = await discover(`
          <div><div class="label">Documents</div>
            <label for="resume">Attach</label><input id="resume" type="file">
            <label for="cover_letter">Attach</label><input id="cover_letter" type="file"></div>`);
        assert.equal(fileLabel(fs, 'resume').label, 'Attach');
        assert.equal(fileLabel(fs, 'cover_letter').label, 'Attach');
    });

    it('two different headings in one single-upload container are ambiguous: the original label stands', async () => {
        const fs = await discover(`
          <div><div class="label">Resume/CV</div><div class="label">Optional</div>
            <label for="resume">Attach</label><input id="resume" type="file"></div>`);
        assert.equal(fileLabel(fs, 'resume').label, 'Attach');
    });

    it('a specific own label is never replaced by a heading', async () => {
        const fs = await discover(`
          <div><div class="label">Some heading</div>
            <label for="resume">Upload your CV</label><input id="resume" type="file"></div>`);
        assert.equal(fileLabel(fs, 'resume').label, 'Upload your CV');
    });

    it('only generic headings: nothing is invented', async () => {
        const fs = await discover(`
          <div><div class="label">Attach</div>
            <label for="resume">Attach</label><input id="resume" type="file"></div>`);
        assert.equal(fileLabel(fs, 'resume').label, 'Attach');
    });

    it('identity stays stable: re-discovery keeps ids and keys, and the heading is the same', async () => {
        const page = await browser.newPage();
        const body = `
          <div><div class="label">Resume/CV</div><label for="resume">Attach</label><input id="resume" type="file"></div>
          <div><div class="label">Cover Letter</div><label for="cover_letter">Attach</label><input id="cover_letter" type="file"></div>`;
        const first = await discover(body, page);
        const second = (await discoverForm(page)).fields;
        assert.deepEqual(
            second.map((f) => [f.id, f.key, f.label]),
            first.map((f) => [f.id, f.key, f.label]),
        );
        await page.close();
    });

    it('through the engine, the questions are called Resume/CV and Cover Letter, and the resume is still tracked', async () => {
        const html = await fixtureHtml('greenhouse-upload.html');
        server.add('/gh-labels.html', html);
        const run = await runOn(server, '/gh-labels.html', 'greenhouse', {
            options: {
                parserWaitMs: 200,
                uploadWaitMs: 600,
                uploadConfirmWaitMs: 200,
            },
        });
        const labels = run.report.questions
            .filter((q) => q.kind === 'file')
            .map((q) => q.label);
        assert.deepEqual(labels.sort(), ['Cover Letter', 'Resume/CV']);
        assert.equal(
            run.report.resume.identity.logicalKey?.includes('resume'),
            true,
        );
    });
});
