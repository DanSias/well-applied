import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import {
    greenhousePresignRule,
    greenhouseResumeUploadException,
} from '../src/adapters/index.ts';
import { startS3Standin, type S3Standin } from './support/s3.ts';
import { startServer, type TestServer } from './support/server.ts';
import {
    fixtureHtml,
    GH_SPONSOR_QUESTION,
    RESUME_PATH,
    runOn,
} from './support/run.ts';

let server: TestServer;
let s3: S3Standin;
const bytes = readFileSync(RESUME_PATH);
const RESUME = {
    sha256: createHash('sha256').update(bytes).digest('hex'),
    bytes: bytes.length,
    filename: basename(RESUME_PATH),
};
// Greenhouse's attachment is an upload step: run it through the same policy the production adapter grants, for local origins.
const policy = () => ({
    uploadExceptions: [greenhouseResumeUploadException(s3.origin, RESUME)],
    authorizationRules: [greenhousePresignRule([server.origin])],
});
before(async () => {
    server = await startServer();
    s3 = await startS3Standin();
    server.presignUrl = `${s3.origin}/`;
    // The same form with the free-text "why us" question made optional: Phase 0 never writes free text.
    const html = (await fixtureHtml('greenhouse-form.html'))
        .replace(
            /Why do you want to work here\?\s*\*/,
            'Why do you want to work here?',
        )
        .replace(/<textarea[^>]*id="question_3"[^>]*>/, (tag) =>
            tag
                .replace(/\s+required/, '')
                .replace(/\s+aria-required="true"/, ''),
        );
    server.add('/greenhouse-optional-essay.html', html);
});
after(async () => {
    await server.close();
    await s3.close();
});

describe('Greenhouse-style form (same engine, different adapter)', () => {
    it('fills the standard fields, attaches the resume and reports what still needs a person', async () => {
        const { report } = await runOn(
            server,
            '/greenhouse-form.html',
            'greenhouse',
            { options: policy() },
        );
        assert.equal(report.ats, 'greenhouse');
        assert.equal(report.outcome, 'input_required');

        const get = (name: string) =>
            report.fields.find((f) => f.name === name);
        assert.equal(get('first_name')?.actual, 'Alex');
        assert.equal(get('last_name')?.actual, 'Example');
        assert.equal(get('email')?.status, 'filled_verified');
        assert.equal(get('phone')?.status, 'filled_verified');
        assert.equal(get('question_1')?.mappedTo, 'linkedin_url');
        assert.equal(get('question_2')?.mappedTo, 'portfolio_url');
        assert.equal(get('cover_letter')?.status, 'unanswered_optional');
        assert.equal(
            get('question_3')?.status,
            'unresolved_required',
            'a free-text essay is never auto-answered',
        );
        assert.equal(
            get('question_4')?.status,
            'unresolved_required',
            'sponsorship needs an approved answer',
        );

        assert.equal(report.resume.verification, 'upload_request');
        assert.equal(report.resume.verified, true);
        assert.deepEqual(server.unexpected(), []);
    });

    it('is ready_for_review once the only required gaps are approved answers', async () => {
        const { report } = await runOn(
            server,
            '/greenhouse-optional-essay.html',
            'greenhouse',
            {
                input: { answers: { [GH_SPONSOR_QUESTION]: 'No' } },
                options: policy(),
            },
        );
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
        const radio = report.fields.find((f) => f.name === 'question_4');
        assert.equal(
            radio?.label,
            'Will you now or in the future require sponsorship?',
        );
        assert.equal(radio?.labelSource, 'dom_aria_labelledby');
        assert.equal(radio?.actual, 'No');
        assert.equal(
            report.fields.find((f) => f.name === 'gender')?.sensitive,
            true,
        );
        assert.deepEqual(server.unexpected(), []);
    });
});
