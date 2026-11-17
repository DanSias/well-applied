import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { startServer, type TestServer } from './support/server.ts';
import { fixtureHtml, GH_SPONSOR_QUESTION, runOn } from './support/run.ts';

let server: TestServer;
before(async () => {
    server = await startServer();
    // The same form with the free-text "why us" question made optional: Phase 0 never writes free text.
    const html = (await fixtureHtml('greenhouse-form.html'))
        .replace('Why do you want to work here? *', 'Why do you want to work here?')
        .replace('<textarea id="question_3" name="question_3" required aria-required="true">', '<textarea id="question_3" name="question_3">');
    server.add('/greenhouse-optional-essay.html', html);
});
after(async () => server.close());

describe('Greenhouse-style form (same engine, different adapter)', () => {
    it('fills the standard fields, attaches the resume and reports what still needs a person', async () => {
        const { report } = await runOn(server, '/greenhouse-form.html', 'greenhouse');
        assert.equal(report.ats, 'greenhouse');
        assert.equal(report.outcome, 'input_required');

        const get = (name: string) => report.fields.find((f) => f.name === name);
        assert.equal(get('first_name')?.actual, 'Alex');
        assert.equal(get('last_name')?.actual, 'Example');
        assert.equal(get('email')?.status, 'filled_verified');
        assert.equal(get('phone')?.status, 'filled_verified');
        assert.equal(get('question_1')?.mappedTo, 'linkedin_url');
        assert.equal(get('question_2')?.mappedTo, 'portfolio_url');
        assert.equal(get('cover_letter')?.status, 'unanswered_optional');
        assert.equal(get('question_3')?.status, 'unresolved_required', 'a free-text essay is never auto-answered');
        assert.equal(get('question_4')?.status, 'unresolved_required', 'sponsorship needs an approved answer');

        assert.equal(report.resume.verification, 'content_sha256');
        assert.deepEqual(server.mutations, []);
    });

    it('is ready_for_review once the only required gaps are approved answers', async () => {
        const { report } = await runOn(server, '/greenhouse-optional-essay.html', 'greenhouse', { input: { answers: { [GH_SPONSOR_QUESTION]: 'No' } } });
        assert.equal(report.outcome, 'ready_for_review', JSON.stringify(report.reasons));
        const radio = report.fields.find((f) => f.name === 'question_4');
        assert.equal(radio?.label, 'Will you now or in the future require sponsorship?');
        assert.equal(radio?.labelSource, 'dom_aria_labelledby');
        assert.equal(radio?.actual, 'No');
        assert.equal(report.fields.find((f) => f.name === 'gender')?.sensitive, true);
        assert.deepEqual(server.mutations, []);
    });
});
