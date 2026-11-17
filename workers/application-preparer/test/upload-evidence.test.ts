// What a resume upload adds up to, as separate facts: selection, the permitted request, the server's
// response, and the widget. A removed input proves nothing either way; only a 2xx to the authorised
// request plus a file name that appears afterwards counts. Pure rules first, then the real engine
// against a local Greenhouse-style page and an S3 stand-in.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import {
    greenhousePresignRule,
    greenhouseResumeUploadException,
} from '../src/adapters/index.ts';
import type { UploadResponseEvidence } from '../src/contracts.ts';
import { classifyUpload, type UploadFacts } from '../src/upload-outcome.ts';
import { RESUME_PATH, fixtureHtml, runOn } from './support/run.ts';
import { startS3Standin, type S3Standin } from './support/s3.ts';
import { startServer, type TestServer } from './support/server.ts';

const response = (
    over: Partial<UploadResponseEvidence> = {},
): UploadResponseEvidence => ({
    exception: 'greenhouse-resume-upload',
    destination: 'https://bucket.example/',
    status: 204,
    statusText: 'No Content',
    contentLength: 0,
    failure: null,
    durationMs: 40,
    ...over,
});
const facts = (over: Partial<UploadFacts> = {}): UploadFacts => ({
    applicable: true,
    selected: true,
    artifactSha256Matches: true,
    response: response(),
    requestAllowed: true,
    refusals: 0,
    filenameVisibleBefore: false,
    filenameVisible: true,
    ...over,
});

describe('classifyUpload (pure)', () => {
    it('accepted and the file name visible', () =>
        assert.equal(
            classifyUpload(facts()).outcome,
            'accepted_filename_visible',
        ));
    it('accepted, widget confirmation missing', () =>
        assert.equal(
            classifyUpload(facts({ filenameVisible: false })).outcome,
            'accepted_confirmation_missing',
        ));
    it('a file name that was already on the page proves nothing', () =>
        assert.equal(
            classifyUpload(facts({ filenameVisibleBefore: true })).outcome,
            'accepted_confirmation_missing',
        ));
    it('any 2xx is acceptance (S3 answers 200, 201 or 204)', () => {
        for (const status of [200, 201, 204])
            assert.equal(
                classifyUpload(facts({ response: response({ status }) }))
                    .outcome,
                'accepted_filename_visible',
            );
    });
    it('3xx, 4xx and 5xx are failures, even when the file name is visible', () => {
        for (const status of [301, 303, 400, 403, 500])
            assert.equal(
                classifyUpload(facts({ response: response({ status }) }))
                    .outcome,
                'failed',
                String(status),
            );
    });
    it('a network failure is a failure', () =>
        assert.equal(
            classifyUpload(
                facts({
                    response: response({
                        status: null,
                        failure: 'net::ERR_FAILED',
                    }),
                }),
            ).outcome,
            'failed',
        ));
    it('a request with no response yet is unknown, never accepted', () => {
        assert.equal(
            classifyUpload(facts({ response: null })).outcome,
            'unknown',
        );
        assert.equal(
            classifyUpload(facts({ response: response({ status: null }) }))
                .outcome,
            'unknown',
        );
    });
    it('selected but no permitted request was sent', () =>
        assert.equal(
            classifyUpload(facts({ requestAllowed: false, response: null }))
                .outcome,
            'no_upload_request',
        ));
    it('a refused upload-like request wins over everything after it', () =>
        assert.equal(
            classifyUpload(facts({ refusals: 1 })).outcome,
            'unauthorized_request',
        ));
    it('selection failed, or the artifact does not hash to the trusted resume', () => {
        assert.equal(
            classifyUpload(facts({ selected: false })).outcome,
            'selection_failed',
        );
        assert.equal(
            classifyUpload(facts({ artifactSha256Matches: false })).outcome,
            'selection_failed',
        );
    });
    it('not applicable when the ATS has no separate upload step', () =>
        assert.equal(
            classifyUpload(facts({ applicable: false })).outcome,
            'not_applicable',
        ));
});

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

const reset = () => {
    s3.uploads.length = 0;
    s3.status = 204;
    s3.delayMs = 0;
    server.presignUrl = `${s3.origin}/`;
};
const variant = (path: string, script: string) => {
    server.add(
        path,
        html.replace('</body>', `<script>${script}</script></body>`),
    );
    return path;
};
const run = (
    path = '/greenhouse-upload.html',
    over: Record<string, unknown> = {},
) =>
    runOn(server, path, 'greenhouse', {
        options: {
            parserWaitMs: 300,
            uploadWaitMs: 2_000,
            uploadConfirmWaitMs: 600,
            uploadExceptions: [
                greenhouseResumeUploadException(s3.origin, RESUME),
            ],
            authorizationRules: [greenhousePresignRule([server.origin])],
            ...over,
        },
    });

describe('resume upload evidence through the engine', () => {
    it('accepted and visible: records the status and non-sensitive metadata, and verifies', async () => {
        reset();
        const { report } = await run();
        const u = report.resume.upload;
        assert.equal(u.outcome, 'accepted_filename_visible');
        assert.equal(u.artifactSha256Matches, true);
        assert.equal(u.response?.status, 204);
        assert.equal(u.response?.destination, `${s3.origin}/`);
        assert.equal(u.filenameVisibleBefore, false);
        assert.equal(u.filenameVisible, true);
        assert.equal(report.resume.verification, 'upload_accepted');
        assert.equal(report.resume.verified, true);
        // Only these keys are ever recorded about the response: no body, headers or query.
        assert.deepEqual(Object.keys(u.response!).sort(), [
            'contentLength',
            'destination',
            'durationMs',
            'exception',
            'failure',
            'status',
            'statusText',
        ]);
        assert.doesNotMatch(
            JSON.stringify(report),
            /AWSAccessKeyId|x-amz-signature|policy|authenticity_token/i,
        );
        assert.equal(s3.uploads.length, 1);
        assert.equal(s3.uploads[0]!.fileSha256, RESUME.sha256);
    });

    it('the widget removes the input before the worker can read it (the live Greenhouse race): still verified from the response and widget', async () => {
        reset();
        const path = variant(
            '/gh-fast-remove.html',
            `document.getElementById('resume').addEventListener('change', (e) => e.target.remove());`,
        );
        const { report } = await run(path);
        assert.equal(
            report.resume.inputHeldChosenResume,
            false,
            'the input was already gone when it was hashed',
        );
        assert.equal(report.resume.identity.tracking, 'removed');
        assert.equal(report.resume.upload.outcome, 'accepted_filename_visible');
        assert.equal(report.resume.verification, 'upload_accepted');
        assert.equal(report.resume.verified, true);
        assert.equal(s3.uploads.length, 1);
    });

    it('a vanishing input alone is NOT evidence: removed, but the bucket refused', async () => {
        reset();
        s3.status = 403;
        const path = variant(
            '/gh-removed-failed.html',
            `document.getElementById('resume').addEventListener('change', (e) => e.target.remove());`,
        );
        const { report } = await run(path);
        assert.equal(report.resume.upload.outcome, 'failed');
        assert.equal(report.resume.upload.response?.status, 403);
        assert.equal(report.resume.verified, false);
        assert.equal(report.outcome, 'blocked');
        assert.match(report.reasons.join(' '), /upload failed/);
    });

    it('failed upload: a 5xx', async () => {
        reset();
        s3.status = 500;
        const { report } = await run();
        assert.equal(report.resume.upload.outcome, 'failed');
        assert.equal(report.resume.upload.response?.status, 500);
        assert.equal(report.resume.verified, false);
    });

    it('response unknown: the answer does not arrive within the bounded wait', async () => {
        reset();
        s3.delayMs = 2_500;
        const { report } = await run('/greenhouse-upload.html', {
            uploadWaitMs: 400,
        });
        assert.equal(report.resume.upload.outcome, 'unknown');
        assert.equal(report.resume.upload.response?.status, null);
        assert.equal(report.resume.verified, false);
        assert.equal(report.outcome, 'blocked');
    });

    it('accepted, but the widget never shows the file name: confirmation missing, not verified', async () => {
        reset();
        const path = variant(
            '/gh-no-confirm.html',
            `new MutationObserver(() => document.querySelectorAll('.filename').forEach((n) => n.remove())).observe(document.body, { childList: true, subtree: true });`,
        );
        const { report } = await run(path);
        assert.equal(report.resume.upload.response?.status, 204);
        assert.equal(
            report.resume.upload.outcome,
            'accepted_confirmation_missing',
        );
        assert.equal(report.resume.verified, false);
        assert.notEqual(report.resume.verification, 'upload_accepted');
    });

    it('accepted, but the widget shows a different file name: mismatch is not verified', async () => {
        reset();
        const path = variant(
            '/gh-other-name.html',
            `new MutationObserver(() => document.querySelectorAll('.filename').forEach((n) => { if (n.textContent !== 'someone-elses-cv.pdf') n.textContent = 'someone-elses-cv.pdf'; })).observe(document.body, { childList: true, subtree: true });`,
        );
        const { report } = await run(path);
        assert.equal(
            report.resume.upload.outcome,
            'accepted_confirmation_missing',
        );
        assert.equal(report.resume.verified, false);
    });

    it('a file name already on the page does not count as the widget confirming', async () => {
        reset();
        s3.status = 204;
        const path = variant(
            '/gh-name-before.html',
            `const p = document.createElement('p'); p.textContent = 'Last time you used sample-resume.pdf'; document.body.append(p); new MutationObserver(() => document.querySelectorAll('.filename').forEach((n) => n.remove())).observe(document.querySelector('.upload-widget'), { childList: true });`,
        );
        const { report } = await run(path);
        assert.equal(report.resume.upload.filenameVisibleBefore, true);
        assert.equal(
            report.resume.upload.outcome,
            'accepted_confirmation_missing',
        );
        assert.equal(report.resume.verified, false);
    });

    it('no permitted request is sent: no_upload_request, not accepted', async () => {
        reset();
        server.presignUrl = null; // the page has nothing to upload to
        const { report } = await run('/greenhouse-upload.html', {
            uploadWaitMs: 600,
        });
        assert.equal(report.resume.upload.outcome, 'no_upload_request');
        assert.equal(report.resume.upload.response, null);
        assert.equal(report.resume.verified, false);
    });

    it('an unauthorised upload (wrong destination) is reported as such, and nothing reaches the bucket', async () => {
        reset();
        server.presignUrl = `${s3.origin}/`;
        const path = variant(
            '/gh-unauth.html',
            `XMLHttpRequest.prototype.open = new Proxy(XMLHttpRequest.prototype.open, { apply(t, self, args) { args[1] = args[1].replace(/\\/$/, '/elsewhere'); return Reflect.apply(t, self, args); } });`,
        );
        const { report } = await run(path);
        assert.equal(report.resume.upload.outcome, 'unauthorized_request');
        assert.equal(report.resume.upload.refusals.length, 1);
        assert.match(report.resume.upload.refusals[0]!, /^POST .*\/elsewhere$/);
        assert.equal(report.outcome, 'safety_failure');
        assert.equal(s3.uploads.length, 0);
    });

    it('the bytes handed to the browser are exactly the trusted resume', async () => {
        reset();
        const { report } = await run();
        assert.equal(report.resume.sha256, RESUME.sha256);
        assert.equal(report.resume.upload.artifactSha256Matches, true);
        assert.equal(s3.uploads[0]!.fileSha256, RESUME.sha256);
    });

    it('the Snowplow analytics beacon fired during the run is kept blocked and reported, and is not a safety failure', async () => {
        reset();
        const path = variant(
            '/gh-telemetry.html',
            `setTimeout(() => fetch('https://c.spl.greenhouse.io/com.snowplowanalytics.snowplow/tp2', { method: 'POST', headers: { 'content-type': 'application/json' }, body: '{}' }).catch(() => {}), 100);`,
        );
        const { report } = await run(path);
        assert.ok(
            report.safetyEvents.some(
                (e) => e.kind === 'blocked_telemetry_request',
            ),
            'retained in the safety report',
        );
        assert.equal(report.submission.blockedAttempts, 0);
        assert.notEqual(report.outcome, 'safety_failure');
        assert.equal(report.resume.upload.outcome, 'accepted_filename_visible');
    });
});
