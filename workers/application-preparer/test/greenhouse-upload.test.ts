// The Greenhouse-style resume upload through the real engine, a local page and a local S3 stand-in.
// Production grants the exception only for the real Greenhouse origins, so these runs pass the SAME
// factories with local origins; the last test shows the production adapter is inert here.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import {
    greenhousePresignRule,
    greenhouseResumeUploadException,
} from '../src/adapters/index.ts';
import { RESUME_PATH, fixtureHtml, runOn } from './support/run.ts';
import { startS3Standin, type S3Standin } from './support/s3.ts';
import { startServer, type TestServer } from './support/server.ts';

let server: TestServer;
let s3: S3Standin;
let html: string;
const bytes = readFileSync(RESUME_PATH);
const RESUME = {
    sha256: createHash('sha256').update(bytes).digest('hex'),
    bytes: bytes.length,
    filename: basename(RESUME_PATH),
};

before(async () => {
    server = await startServer();
    s3 = await startS3Standin();
    html = await fixtureHtml('greenhouse-upload.html');
});
after(async () => {
    await server.close();
    await s3.close();
});

const reset = (presign = true) => {
    s3.uploads.length = 0;
    s3.status = 204;
    server.presignUrl = presign ? `${s3.origin}/` : null;
};
const policy = () => ({
    uploadExceptions: [greenhouseResumeUploadException(s3.origin, RESUME)],
    authorizationRules: [greenhousePresignRule([server.origin])],
});
const run = (
    path = '/greenhouse-upload.html',
    options: Record<string, unknown> = policy(),
) =>
    runOn(server, path, 'greenhouse', {
        options: { parserWaitMs: 300, ...options },
    });
const byDomId = (r: Awaited<ReturnType<typeof run>>, id: string) =>
    r.report.fields.find((f) => f.domId === id);

describe('Greenhouse-style resume upload through the engine', () => {
    it('attaches the resume to the authorised destination once, verifies it from three independent facts, and keeps the identities straight', async () => {
        reset();
        const r = await run();
        const { report } = r;

        // What really reached the bucket: one upload, the chosen resume byte for byte, nothing else.
        assert.equal(s3.uploads.length, 1);
        assert.equal(s3.uploads[0]!.method, 'POST');
        assert.equal(s3.uploads[0]!.fileSha256, RESUME.sha256);
        assert.ok(s3.uploads[0]!.fieldNames.includes('key'));
        assert.deepEqual(server.unexpected(), []);
        assert.deepEqual(
            server.mutations.filter((m) => m !== 'POST /parseResume'),
            [],
        );

        assert.deepEqual(
            report.safetyEvents.map((e) => e.kind),
            ['allowed_exception_used'],
        );
        assert.match(
            report.safetyEvents[0]!.detail,
            /^greenhouse-resume-upload: POST http:\/\/127\.0\.0\.1:\d+\/ \[xhr, multipart\/form-data, \d+B\]$/,
        );

        // The three facts behind the verification, each reported separately.
        assert.equal(
            report.resume.inputHeldChosenResume,
            true,
            'the browser held exactly the resume when the upload started',
        );
        assert.equal(
            report.resume.uploadRequestAllowed,
            true,
            'the one validated upload request was allowed',
        );
        assert.equal(report.resume.verification, 'upload_request');
        assert.equal(report.resume.verified, true);

        // The widget removed the resume input; the result stays the resume's, and the cover letter is untouched.
        assert.equal(report.resume.identity.tracking, 'removed');
        const own = report.fields.find(
            (f) => f.presence === 'removed_after_upload',
        )!;
        assert.equal(own.domId, 'resume');
        assert.equal(own.status, 'filled_verified');
        const cover = byDomId(r, 'cover_letter')!;
        assert.equal(cover.status, 'unanswered_optional');
        assert.notEqual(cover.id, own.id);
        assert.equal(
            report.fields.filter((f) => f.status === 'error').length,
            0,
            'no false error on the cover letter',
        );

        assert.equal(report.blockers.captcha, 'none');
        assert.equal(
            report.outcome,
            'ready_for_review',
            JSON.stringify(report.reasons),
        );
    });

    it('a bucket that refuses the upload leaves the resume unverified: the request was allowed, but nothing is claimed', async () => {
        reset();
        s3.status = 403;
        const { report } = await run();
        assert.equal(s3.uploads.length, 1, 'the request reached the bucket');
        assert.equal(report.resume.uploadRequestAllowed, true);
        assert.equal(report.resume.inputHeldChosenResume, true);
        assert.equal(
            report.resume.verified,
            false,
            'the page never showed the file name',
        );
        assert.notEqual(report.resume.verification, 'upload_request');
        assert.equal(report.outcome, 'blocked');
        assert.match(
            report.reasons.join(' '),
            /resume upload could not be verified/,
        );
    });

    it('with no presign authorisation the page cannot upload and the resume is not claimed', async () => {
        reset(false);
        const { report } = await run();
        assert.equal(s3.uploads.length, 0);
        assert.equal(report.resume.verified, false);
        assert.equal(report.resume.uploadRequestAllowed, false);
        assert.equal(report.outcome, 'blocked');
    });

    it('PRODUCTION ADAPTER IS INERT ON A LOCAL ORIGIN: without the explicit local policy the upload is blocked and nothing reaches the bucket', async () => {
        reset();
        const { report } = await run('/greenhouse-upload.html', {}); // no overrides: the real adapter
        assert.equal(s3.uploads.length, 0);
        assert.equal(report.resume.uploadRequestAllowed, false);
        assert.equal(report.resume.verified, false);
        assert.ok(
            report.safetyEvents.some(
                (e) => e.kind === 'blocked_mutating_request',
            ),
        );
        assert.equal(report.outcome, 'safety_failure');
    });

    describe('hostile variants stay blocked', () => {
        const variant = (path: string, script: string) => {
            server.add(
                path,
                html.replace('</body>', `<script>${script}</script></body>`),
            );
            return path;
        };

        it('a page that posts the upload to a path other than the authorised one', async () => {
            reset();
            const path = variant(
                '/gh-wrong-path.html',
                `XMLHttpRequest.prototype.open = new Proxy(XMLHttpRequest.prototype.open, { apply(t, self, args) { args[1] = args[1].replace(/\\/$/, '/other'); return Reflect.apply(t, self, args); } });`,
            );
            const { report } = await run(path);
            assert.equal(s3.uploads.length, 0);
            assert.equal(report.resume.uploadRequestAllowed, false);
            assert.equal(report.outcome, 'safety_failure');
        });

        it('a page that adds an unexpected form field to the upload', async () => {
            reset();
            const path = variant(
                '/gh-extra-field.html',
                `FormData.prototype.append = new Proxy(FormData.prototype.append, { apply(t, self, args) { if (args[0] === 'authenticity_token') Reflect.apply(t, self, ['applicant_phone', '+1 555 010 0199']); return Reflect.apply(t, self, args); } });`,
            );
            const { report } = await run(path);
            assert.equal(s3.uploads.length, 0);
            assert.match(
                report.safetyEvents.map((e) => e.detail).join(' '),
                /unexpected form field name "applicant_phone"/,
            );
            assert.equal(report.outcome, 'safety_failure');
        });

        it('a page that uploads a second time (the cover letter) after the resume', async () => {
            reset();
            const path = variant(
                '/gh-second.html',
                `document.getElementById('resume').addEventListener('change', () => setTimeout(() => { const i = document.getElementById('cover_letter'); const dt = new DataTransfer(); dt.items.add(new File(['x'], 'sample-resume.pdf', { type: 'application/pdf' })); i.files = dt.files; i.dispatchEvent(new Event('change', { bubbles: true })); }, 100));`,
            );
            const { report } = await run(path);
            assert.equal(
                s3.uploads.length,
                1,
                'only the first upload went through',
            );
            assert.match(
                report.safetyEvents.map((e) => e.detail).join(' '),
                /already used 1x/,
            );
            assert.equal(report.outcome, 'safety_failure');
        });

        it("a page that tries the upload after the worker's window has closed", async () => {
            reset();
            const path = variant(
                '/gh-late.html',
                `setTimeout(() => { const f = new FormData(); f.append('key', 'k'); f.append('file', new Blob([]), 'sample-resume.pdf'); const x = new XMLHttpRequest(); x.open('POST', '${s3.origin}/'); x.send(f); }, 6000);`,
            );
            reset();
            const { report } = await run(path);
            assert.equal(
                s3.uploads.length,
                1,
                'only the worker-initiated upload',
            );
            assert.ok(report.safetyEvents.length >= 1);
        });
    });
});
