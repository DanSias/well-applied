// Through the real engine: the upload changes the DOM (as Greenhouse's widget does) and the
// resume's result must stay attached to the resume, never to the cover-letter input.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, type TestServer } from './support/server.ts';
import { LEVER_AUTH_QUESTION, fixtureHtml, runOn } from './support/run.ts';

let server: TestServer;
let base: string;
before(async () => {
    server = await startServer();
    base = await fixtureHtml('lever-form.html');
});
after(async () => server.close());

const ANSWERS = { [LEVER_AUTH_QUESTION]: 'Yes' };
const COVER = `document.querySelector('form ul').insertAdjacentHTML('beforeend', '<li class="application-question"><label><div class="application-label">Cover Letter</div><div class="application-field"><input type="file" name="cover_letter" id="cover_letter"></div></label></li>');`;
const variant = (path: string, script: string) => {
    server.add(
        path,
        base.replace('</body>', `<script>${script}</script></body>`),
    );
    return path;
};
const run = (path: string, options = {}) =>
    runOn(server, path, 'lever', {
        input: { answers: ANSWERS },
        options: { parserWaitMs: 600, ...options },
    });
const cover = (r: Awaited<ReturnType<typeof run>>) =>
    r.report.fields.find((f) => f.domId === 'cover_letter')!;

describe('resume identity through the engine', () => {
    it('REGRESSION: the resume input is removed after the upload; the failure is attributed to the resume, not the cover letter', async () => {
        const path = variant(
            '/identity-removed.html',
            `${COVER} document.getElementById('resume-upload-input').addEventListener('change', () => setTimeout(() => document.getElementById('resume-upload-input').remove(), 150));`,
        );
        const { report } = await run(path);

        assert.equal(report.resume.identity.tracking, 'removed');
        assert.match(report.resume.identity.note, /no longer on the page/);

        const own = report.fields.find(
            (f) => f.presence === 'removed_after_upload',
        );
        assert.ok(own, 'the resume keeps its own result');
        assert.equal(own!.mappedTo, 'resume');
        assert.equal(own!.domId, 'resume-upload-input');
        assert.equal(own!.status, 'error');
        assert.match(own!.note!, /removed from the page after the upload/);
        assert.equal(report.resume.fieldId, own!.id);

        const c = cover(await run(path));
        assert.equal(
            c.status,
            'unanswered_optional',
            'the cover letter is untouched',
        );
        assert.notEqual(c.id, own!.id);
        assert.equal(c.note, 'cover letter: not supplied in Phase 0');
        assert.equal(report.resume.verified, false);
        assert.equal(report.outcome, 'blocked');
    });

    it('the input is replaced by a new node with the same identity: it is re-found by key and attributed correctly', async () => {
        const path = variant(
            '/identity-rerendered.html',
            `${COVER} document.getElementById('resume-upload-input').addEventListener('change', () => setTimeout(() => { const old = document.getElementById('resume-upload-input'); const n = document.createElement('input'); n.type = 'file'; n.id = old.id; n.name = old.name; n.className = old.className; old.replaceWith(n); }, 150));`,
        );
        const { report } = await run(path);
        assert.equal(report.resume.identity.tracking, 'rematched_by_key');
        const own = report.fields.find((f) => f.id === report.resume.fieldId)!;
        assert.equal(own.domId, 'resume-upload-input');
        assert.equal(own.mappedTo, 'resume');
        assert.equal(
            own.presence,
            undefined,
            'it is present, just re-rendered',
        );
        assert.equal(
            report.fields.filter(
                (f) => f.mappedTo === 'resume' && f.kind === 'file',
            ).length,
            1,
        );
        // The new element holds no file, which is reported honestly rather than assumed.
        assert.equal(report.resume.verified, false);
        assert.equal(cover({ report } as never).status, 'unanswered_optional');
    });

    it('resume and cover letter coexisting and unchanged: each keeps its own identity and result', async () => {
        const path = variant('/identity-coexist.html', COVER);
        const { report } = await run(path);
        assert.equal(report.resume.identity.tracking, 'same_element');
        assert.equal(report.resume.verified, true);
        const own = report.fields.find((f) => f.id === report.resume.fieldId)!;
        assert.equal(own.domId, 'resume-upload-input');
        assert.equal(own.status, 'filled_verified');
        assert.equal(cover({ report } as never).status, 'unanswered_optional');
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
    });

    it('AMBIGUOUS: two look-alike file inputs and the one that received the resume vanishes: no current control is blamed', async () => {
        // Two inputs with identical identifying properties (no name, no id, same label).
        const twin = `<li class="application-question"><label><div class="application-label">Resume/CV</div><div class="application-field"><input type="file" class="twin"></div></label></li>`;
        const html = base
            .replace(
                /<li class="application-question resume">[\s\S]*?<\/li>/,
                twin + twin,
            )
            .replace(
                '</body>',
                `<script>document.querySelectorAll('input.twin').forEach((i) => i.addEventListener('change', () => setTimeout(() => i.remove(), 150)));</script></body>`,
            );
        server.add('/identity-ambiguous.html', html);
        const { report } = await run('/identity-ambiguous.html');
        assert.equal(report.resume.identity.tracking, 'ambiguous');
        const own = report.fields.find(
            (f) => f.presence === 'ambiguous_after_upload',
        );
        assert.ok(own);
        assert.equal(own!.status, 'error');
        // The surviving look-alike is not treated as the resume and not blamed for its failure.
        const survivor = report.fields.find(
            (f) => f.kind === 'file' && f.presence === undefined,
        );
        assert.ok(survivor);
        assert.notEqual(survivor!.status, 'error');
        assert.match(
            survivor!.note ?? '',
            /another file input that looks like a resume/,
        );
        assert.equal(report.resume.verified, false);
    });
});
