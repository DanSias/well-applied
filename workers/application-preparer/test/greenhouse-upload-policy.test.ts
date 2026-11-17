// The Greenhouse resume-upload exception: pure rules, then the real guard against a local S3 stand-in.
// Nothing here touches a real bucket or a real Greenhouse origin.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium, type Browser, type Page } from 'playwright';
import {
    GREENHOUSE_PAGE_ORIGINS,
    GREENHOUSE_PRESIGN_RULE,
    GREENHOUSE_S3_ORIGIN,
    greenhouse,
    greenhousePresignRule,
    greenhouseResumeUploadException,
    lever,
} from '../src/adapters/index.ts';
import { SubmissionGuard } from '../src/guard.ts';
import {
    matchException,
    parseMultipart,
    validateS3ResumePost,
    type RequestFacts,
} from '../src/request-policy.ts';
import { RESUME_PATH } from './support/run.ts';
import { multipart, startS3Standin, type S3Standin } from './support/s3.ts';
import { startServer, type TestServer } from './support/server.ts';

const bytes = readFileSync(RESUME_PATH);
const RESUME = {
    sha256: createHash('sha256').update(bytes).digest('hex'),
    bytes: bytes.length,
    filename: 'sample-resume.pdf',
};
const S3 = GREENHOUSE_S3_ORIGIN;
const DEST = `${S3}/`;

const SIGNED = [
    { name: 'utf8', value: '✓' },
    { name: 'policy', value: 'secret-policy-value' },
    { name: 'x-amz-algorithm', value: 'AWS4-HMAC-SHA256' },
    { name: 'x-amz-signature', value: 'secret-signature-value' },
    { name: 'key', value: 'uploads/123/abc/resume' },
    { name: 'authenticity_token', value: '1234' },
    { name: 'Content-Type', value: 'application/octet-stream' },
];
const upload = (
    over: { parts?: Parameters<typeof multipart>[0]; file?: Buffer } = {},
) =>
    multipart(
        over.parts ?? [
            ...SIGNED,
            {
                name: 'file',
                value: over.file ?? bytes,
                filename: 'sample-resume.pdf',
            },
        ],
    );

// What the guard really receives from Chromium: the file part is present but EMPTY.
const uploadOmitted = (over: { filename?: string } = {}) =>
    multipart([
        ...SIGNED,
        {
            name: 'file',
            value: Buffer.alloc(0),
            filename: over.filename ?? 'sample-resume.pdf',
        },
    ]);

describe('parseMultipart', () => {
    it('splits a browser-shaped body into named parts and a file part', () => {
        const { body, contentType } = upload();
        const parts = parseMultipart(body, contentType)!;
        assert.deepEqual(
            parts.map((p) => p.name),
            [...SIGNED.map((s) => s.name), 'file'],
        );
        assert.equal(parts.at(-1)!.filename, 'sample-resume.pdf');
        assert.ok(parts.at(-1)!.bytes.equals(bytes));
    });
    it('accepts a quoted boundary', () => {
        const { body } = upload();
        assert.ok(
            parseMultipart(
                body,
                'multipart/form-data; boundary="----WebKitFormBoundaryTest1234"',
            ),
        );
    });
    for (const [name, ct, mutate] of [
        ['no boundary parameter', 'multipart/form-data', (b: Buffer) => b],
        [
            'wrong boundary',
            'multipart/form-data; boundary=nope',
            (b: Buffer) => b,
        ],
        [
            'truncated body',
            undefined,
            (b: Buffer) => b.subarray(0, b.length - 30),
        ],
        [
            'no closing delimiter',
            undefined,
            (b: Buffer) =>
                b.subarray(
                    0,
                    b.indexOf(
                        Buffer.from('\r\n------WebKitFormBoundaryTest1234--'),
                    ),
                ),
        ],
        ['empty body', undefined, () => Buffer.alloc(0)],
        ['not multipart at all', undefined, () => Buffer.from('{"a":1}')],
    ] as const) {
        it(`returns null for: ${name}`, () => {
            const { body, contentType } = upload();
            assert.equal(parseMultipart(mutate(body), ct ?? contentType), null);
        });
    }
});

describe('validateS3ResumePost', () => {
    const check = (parts?: Parameters<typeof multipart>[0], file?: Buffer) => {
        const { body, contentType } = upload({ parts, file });
        return validateS3ResumePost(body, contentType, RESUME);
    };

    it('accepts a browser upload of exactly the chosen resume with allowlisted signed fields', () => {
        assert.equal(check(), null);
    });

    describe('as Chromium really delivers it: the file part is present but its bytes are omitted', () => {
        it("accepts it when the file name is the chosen resume's", () => {
            const { body, contentType } = uploadOmitted();
            assert.equal(validateS3ResumePost(body, contentType, RESUME), null);
        });
        it('refuses a different file name', () => {
            const { body, contentType } = uploadOmitted({
                filename: 'something-else.pdf',
            });
            assert.match(
                validateS3ResumePost(body, contentType, RESUME)!,
                /does not carry the chosen resume's file name/,
            );
        });
        it('still applies every structural rule (e.g. an unexpected field)', () => {
            const { body, contentType } = multipart([
                { name: 'cookie', value: 'x' },
                ...SIGNED,
                {
                    name: 'file',
                    value: Buffer.alloc(0),
                    filename: 'sample-resume.pdf',
                },
            ]);
            assert.match(
                validateS3ResumePost(body, contentType, RESUME)!,
                /unexpected form field name "cookie"/,
            );
        });
    });

    for (const [name, parts, pattern] of [
        ['no file part', SIGNED, /exactly one file part, found 0/],
        [
            'two file parts',
            [
                ...SIGNED,
                { name: 'file', value: bytes, filename: 'a.pdf' },
                { name: 'file', value: bytes, filename: 'b.pdf' },
            ],
            /exactly one file part, found 2/,
        ],
        [
            'the file part is not last',
            [
                ...SIGNED.slice(0, 3),
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
                ...SIGNED.slice(3),
            ],
            /not last/,
        ],
        [
            'the file part is named something else',
            [
                ...SIGNED,
                {
                    name: 'attachment',
                    value: bytes,
                    filename: 'sample-resume.pdf',
                },
            ],
            /not named "file"/,
        ],
        [
            'an unexpected form field name',
            [
                { name: 'cookie', value: 'x' },
                ...SIGNED,
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ],
            /unexpected form field name "cookie"/,
        ],
        [
            'a form field that carries a file',
            [
                ...SIGNED,
                { name: 'policy2', value: 'x', filename: 'x.bin' },
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ],
            /exactly one file part, found 2/,
        ],
        [
            'a repeated form field',
            [
                ...SIGNED,
                { name: 'key', value: 'again' },
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ],
            /repeated/,
        ],
        [
            'an oversized form field',
            [
                ...SIGNED.filter((s) => s.name !== 'policy'),
                { name: 'policy', value: 'x'.repeat(5000) },
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ],
            /larger than 4096 bytes/,
        ],
        [
            'no key field',
            [
                ...SIGNED.filter((s) => s.name !== 'key'),
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ],
            /no "key" form field/,
        ],
    ] as const) {
        it(`refuses: ${name}`, () => assert.match(check([...parts])!, pattern));
    }

    it('refuses a file part that is not the chosen resume (one byte different, same size)', () => {
        const other = Buffer.from(bytes);
        other[other.length - 1] = (other[other.length - 1] ?? 0) ^ 0xff;
        assert.match(check(undefined, other)!, /not the chosen resume/);
    });
    it('refuses a file part of a different size', () => {
        assert.match(
            check(undefined, Buffer.concat([bytes, Buffer.from('x')]))!,
            /not the size of the chosen resume/,
        );
    });
    it('refuses a non-multipart body and a malformed one', () => {
        assert.match(
            validateS3ResumePost(
                Buffer.from('{}'),
                'application/json',
                RESUME,
            )!,
            /not multipart/,
        );
        assert.match(
            validateS3ResumePost(
                Buffer.from('garbage'),
                'multipart/form-data; boundary=x',
                RESUME,
            )!,
            /malformed/,
        );
    });
    it('NEVER puts a value in the reason (only names), so a report cannot leak signed fields', () => {
        const reasons = [
            check([
                { name: 'cookie', value: 'secret-cookie-value' },
                ...SIGNED,
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ]),
            check([
                ...SIGNED.filter((s) => s.name !== 'key'),
                { name: 'file', value: bytes, filename: 'sample-resume.pdf' },
            ]),
            check(undefined, Buffer.from('secret-file-body')),
        ].join(' ');
        assert.doesNotMatch(reasons, /secret-/);
    });
});

describe('the Greenhouse exception (pure matching)', () => {
    const rule = greenhouseResumeUploadException(S3, RESUME);
    const good = (
        over: Partial<RequestFacts> = {},
        url = DEST,
    ): RequestFacts => {
        const { body, contentType } = upload();
        return {
            method: 'POST',
            url: new URL(url),
            resourceType: 'xhr',
            contentType,
            bodyBytes: body.length,
            body,
            learned: { [GREENHOUSE_PRESIGN_RULE]: [DEST] },
            isMainFrame: true,
            uploadWindowOpen: true,
            priorUses: 0,
            ...over,
        };
    };

    it('matches exactly the observed request when the destination was named by the page and the body is the chosen resume', () => {
        assert.deepEqual(matchException(rule, good()), { matched: true });
    });

    it('matches the body exactly as Chromium delivers it (file bytes omitted)', () => {
        const { body, contentType } = uploadOmitted();
        assert.deepEqual(
            matchException(
                rule,
                good({ body, contentType, bodyBytes: body.length }),
            ),
            { matched: true },
        );
    });

    it('is exact on origin, path and method', () => {
        assert.deepEqual(
            {
                name: rule.name,
                method: rule.method,
                origin: rule.origin,
                path: rule.pathPattern.source,
                types: rule.resourceTypes,
                ct: rule.contentTypePrefix,
                once: rule.maxUses,
                windowOnly: rule.duringUploadOnly,
            },
            {
                name: 'greenhouse-resume-upload',
                method: 'POST',
                origin: 'https://grnhse-prod-jben-us-east-1.s3.amazonaws.com',
                path: '^\\/$',
                types: ['xhr'],
                ct: 'multipart/form-data',
                once: 1,
                windowOnly: true,
            },
        );
    });

    describe('near-matching origins are refused, even if a page "authorised" them', () => {
        for (const origin of [
            'http://grnhse-prod-jben-us-east-1.s3.amazonaws.com',
            'https://grnhse-prod-jben-eu-west-1.s3.amazonaws.com',
            'https://grnhse-prod-jben-us-east-2.s3.amazonaws.com',
            'https://grnhse-prod-jben-us-east-1.s3.amazonaws.com:8443',
            'https://evil.grnhse-prod-jben-us-east-1.s3.amazonaws.com',
            'https://grnhse-prod-jben-us-east-1.s3.amazonaws.com.evil.example',
            'https://s3.amazonaws.com',
            'https://other-bucket.s3.amazonaws.com',
            'https://job-boards.greenhouse.io',
            'https://boards-api.greenhouse.io',
        ]) {
            it(`refuses ${origin}`, () => {
                const url = `${origin}/`;
                const r = matchException(
                    rule,
                    good(
                        { learned: { [GREENHOUSE_PRESIGN_RULE]: [url] } },
                        url,
                    ),
                );
                assert.equal(r.matched, false);
            });
        }
    });

    describe('near-matching paths are refused', () => {
        for (const path of [
            '',
            '/x',
            '//',
            '/resume',
            '/uploads/key',
            '/?x=1',
            '/%2F',
        ]) {
            it(`refuses path ${JSON.stringify(path)}`, () => {
                const url = `${S3}${path}`;
                assert.equal(
                    matchException(
                        rule,
                        good(
                            {
                                learned: {
                                    [GREENHOUSE_PRESIGN_RULE]: [url, `${S3}/`],
                                },
                            },
                            url,
                        ),
                    ).matched,
                    path === '',
                );
            });
        }
    });

    describe('every other constraint is enforced', () => {
        const cases: Array<[string, Partial<RequestFacts>, RegExp?]> = [
            ['PUT', { method: 'PUT' }],
            ['PATCH', { method: 'PATCH' }],
            ['DELETE', { method: 'DELETE' }],
            [
                'fetch (the widget uses XHR)',
                { resourceType: 'fetch' },
                /resource type fetch/,
            ],
            [
                'document (a form-post navigation)',
                { resourceType: 'document' },
                /resource type document/,
            ],
            ['ping', { resourceType: 'ping' }, /resource type ping/],
            [
                'JSON content type',
                { contentType: 'application/json' },
                /content-type application\/json/,
            ],
            [
                'urlencoded content type',
                { contentType: 'application/x-www-form-urlencoded' },
                /content-type/,
            ],
            [
                'outside the resume attachment window',
                { uploadWindowOpen: false },
                /not during a worker-initiated/,
            ],
            ['a second upload', { priorUses: 1 }, /already used 1x/],
            ['from a child frame', { isMainFrame: false }, /child frame/],
            [
                'oversized body',
                { bodyBytes: RESUME.bytes + 40_000 },
                /too large/,
            ],
            ['unknown body size', { bodyBytes: null }, /too large/],
            ['body not readable', { body: null }, /could not be read/],
            [
                'no authorisation learned',
                { learned: {} },
                /not named by the page's own authorisation/,
            ],
            [
                'authorisation named a different path',
                { learned: { [GREENHOUSE_PRESIGN_RULE]: [`${S3}/other`] } },
                /not named by the page/,
            ],
            [
                'authorisation named a different origin',
                {
                    learned: {
                        [GREENHOUSE_PRESIGN_RULE]: [
                            'https://other-bucket.s3.amazonaws.com/',
                        ],
                    },
                },
                /not named by the page/,
            ],
            [
                'authorisation under a different rule name',
                { learned: { somethingElse: [DEST] } },
                /not named by the page/,
            ],
        ];
        for (const [name, over, pattern] of cases) {
            it(`refuses: ${name}`, () => {
                const r = matchException(rule, good(over));
                assert.equal(r.matched, false);
                if (pattern)
                    assert.match((r as { nearMiss: string }).nearMiss, pattern);
            });
        }
    });

    describe('payloads', () => {
        const withBody = (
            parts: Parameters<typeof multipart>[0],
            file?: Buffer,
        ) => {
            const { body, contentType } = upload({ parts, file });
            return good({ body, contentType, bodyBytes: body.length });
        };
        it('refuses a different file in the file part', () => {
            const other = Buffer.from(bytes);
            other[10] = (other[10] ?? 0) ^ 1;
            const r = matchException(
                rule,
                withBody([
                    ...SIGNED,
                    {
                        name: 'file',
                        value: other,
                        filename: 'sample-resume.pdf',
                    },
                ]),
            );
            assert.match(
                (r as { nearMiss: string }).nearMiss,
                /body: the file part is not the chosen resume/,
            );
        });
        it('refuses extra files', () => {
            const r = matchException(
                rule,
                withBody([
                    ...SIGNED,
                    { name: 'file', value: bytes, filename: 'a.pdf' },
                    { name: 'file', value: bytes, filename: 'b.pdf' },
                ]),
            );
            assert.equal(r.matched, false);
        });
        it('refuses an unexpected field', () => {
            const r = matchException(
                rule,
                withBody([
                    { name: 'applicant_email', value: 'a@b.c' },
                    ...SIGNED,
                    {
                        name: 'file',
                        value: bytes,
                        filename: 'sample-resume.pdf',
                    },
                ]),
            );
            assert.match(
                (r as { nearMiss: string }).nearMiss,
                /unexpected form field name "applicant_email"/,
            );
        });
        it('refuses a JSON body claiming to be multipart', () => {
            assert.equal(
                matchException(
                    rule,
                    good({ body: Buffer.from('{}'), bodyBytes: 2 }),
                ).matched,
                false,
            );
        });
    });
});

describe('who gets the exception (production adapter)', () => {
    it('is granted only for the two observed Greenhouse page origins, with exactly one exception and one rule', () => {
        for (const origin of GREENHOUSE_PAGE_ORIGINS) {
            const ex = greenhouse.uploadExceptions(origin, RESUME);
            assert.equal(ex.length, 1, origin);
            assert.equal(ex[0]!.origin, GREENHOUSE_S3_ORIGIN);
            assert.equal(ex[0]!.authorizedBy, GREENHOUSE_PRESIGN_RULE);
            assert.equal(greenhouse.authorizationRules!(origin).length, 1);
        }
    });
    it('is not granted for anything else: EU boards, employer-hosted pages, http, other ATSs, the S3 host itself', () => {
        for (const origin of [
            'https://job-boards.eu.greenhouse.io',
            'https://careers.example.com',
            'http://job-boards.greenhouse.io',
            'https://jobs.lever.co',
            GREENHOUSE_S3_ORIGIN,
            'http://127.0.0.1:3000',
        ]) {
            assert.deepEqual(
                greenhouse.uploadExceptions(origin, RESUME),
                [],
                origin,
            );
            assert.deepEqual(
                greenhouse.authorizationRules!(origin),
                [],
                origin,
            );
        }
    });
    it('Lever does not inherit it and Greenhouse does not inherit the Lever exception', () => {
        assert.deepEqual(
            lever.uploadExceptions('https://job-boards.greenhouse.io', RESUME),
            [],
        );
        assert.ok(
            greenhouse
                .uploadExceptions('https://job-boards.greenhouse.io', RESUME)
                .every((e) => e.name !== 'lever-resume-parse'),
        );
    });
    it('the presign rule takes only the destination url, nothing else from the answer', () => {
        const r = greenhousePresignRule(GREENHOUSE_PAGE_ORIGINS);
        assert.equal(
            r.destination({
                url: DEST,
                resume: { fields: { policy: 'secret' }, key: 'k' },
            }),
            DEST,
        );
        assert.equal(r.destination({ url: 5 }), null);
        assert.equal(r.destination({}), null);
        assert.equal(r.destination(null), null);
        assert.equal(r.destination('x'), null);
        assert.equal(r.pathSuffix, '/uncacheable_attributes/presigned_fields');
    });
    it('does not authorise submission: nothing resembling the application POST can match', () => {
        const rule = greenhouseResumeUploadException(S3, RESUME);
        for (const url of [
            'https://job-boards.greenhouse.io/discord/jobs/8917177002',
            'https://boards-api.greenhouse.io/v1/boards/discord/jobs/8917177002',
            'https://job-boards.greenhouse.io/embed/job_app',
        ]) {
            const { body, contentType } = upload();
            const r = matchException(rule, {
                method: 'POST',
                url: new URL(url),
                resourceType: 'xhr',
                contentType,
                bodyBytes: body.length,
                body,
                learned: { [GREENHOUSE_PRESIGN_RULE]: [url] },
                isMainFrame: true,
                uploadWindowOpen: true,
                priorUses: 0,
            });
            assert.equal(r.matched, false, url);
        }
    });
});

// ---------------------------------------------------------------------------
// The real guard, a real browser, a local page and a local S3 stand-in.
// ---------------------------------------------------------------------------
describe('the guard against a local S3 stand-in', () => {
    let server: TestServer;
    let s3: S3Standin;
    let other: S3Standin;
    let browser: Browser;
    before(async () => {
        server = await startServer();
        s3 = await startS3Standin();
        other = await startS3Standin();
        browser = await chromium.launch({ headless: true });
    });
    after(async () => {
        await browser.close();
        await server.close();
        await s3.close();
        await other.close();
    });

    async function open(
        presignUrl: string | null = `${s3.origin}/`,
    ): Promise<{ page: Page; guard: SubmissionGuard }> {
        server.presignUrl = presignUrl;
        s3.uploads.length = 0;
        other.uploads.length = 0;
        s3.status = 204;
        const guard = new SubmissionGuard(
            new URL(server.origin),
            [greenhouseResumeUploadException(s3.origin, RESUME)],
            [greenhousePresignRule([server.origin])],
        );
        const context = await browser.newContext({
            viewport: { width: 1200, height: 900 },
        });
        await guard.install(context);
        const page = await context.newPage();
        guard.setMainPage(page);
        await page.goto(`${server.origin}/greenhouse-upload.html`);
        guard.lockNavigation();
        await page.waitForLoadState('networkidle');
        return { page, guard };
    }
    const attach = async (
        page: Page,
        guard: SubmissionGuard,
        selector = '#resume',
        file = RESUME_PATH,
        inWindow = true,
    ) => {
        if (inWindow) guard.openUploadWindow();
        await page.locator(selector).setInputFiles(file);
        await page.waitForTimeout(700);
        guard.closeUploadWindow();
    };
    const kinds = (g: SubmissionGuard) => g.events.map((e) => e.kind);

    it('lets exactly the resume upload through once, to the destination the page named, and records it without any body content', async () => {
        const { page, guard } = await open();
        await attach(page, guard);
        assert.equal(s3.uploads.length, 1);
        assert.equal(
            s3.uploads[0]!.fileSha256,
            RESUME.sha256,
            'the bucket received the chosen resume, byte for byte',
        );
        assert.ok(
            s3.uploads[0]!.fieldNames.includes('key') &&
                s3.uploads[0]!.fieldNames.includes('policy'),
        );
        assert.deepEqual(kinds(guard), ['allowed_exception_used']);
        const detail = guard.events[0]!.detail;
        assert.match(
            detail,
            /^greenhouse-resume-upload: POST http:\/\/127\.0\.0\.1:\d+\/ \[xhr, multipart\/form-data, \d+B\]$/,
        );
        assert.doesNotMatch(
            detail,
            /synthetic-|policy|signature/,
            'no signed field reaches the event log',
        );
        assert.equal(guard.hasFailure(), false);
        await page.context().close();
    });

    it('allows only one upload: a second attempt is refused', async () => {
        const { page, guard } = await open();
        await attach(page, guard);
        await page.evaluate(() =>
            document
                .querySelector('[data-field=resume] .upload-widget')!
                .insertAdjacentHTML(
                    'beforeend',
                    '<input id="resume2" type="file">',
                ),
        );
        // The widget removed its input on success; a script-injected second input would be the page trying again.
        await page.evaluate(() => {
            document
                .querySelector('[data-field=cover_letter] .upload-widget')!
                .setAttribute('data-x', '1');
        });
        await attach(page, guard, '#cover_letter');
        assert.equal(s3.uploads.length, 1, 'maxUses: 1');
        assert.match(guard.events.at(-1)!.detail, /already used 1x/);
        assert.equal(guard.hasFailure(), true);
        await page.context().close();
    });

    it('when the presign endpoint fails the page cannot even attempt an upload; nothing reaches the bucket', async () => {
        const { page, guard } = await open(null);
        await attach(page, guard);
        assert.equal(s3.uploads.length, 0);
        assert.deepEqual(
            guard.events,
            [],
            'no request was made, so nothing to block',
        );
        assert.match(
            await page.locator('[data-field=resume]').innerText(),
            /Upload failed/,
        );
        await page.context().close();
    });

    it('refuses an upload to the right origin when no authorisation was learned (the rule trusts a different page origin)', async () => {
        server.presignUrl = `${s3.origin}/`;
        s3.uploads.length = 0;
        const guard = new SubmissionGuard(
            new URL(server.origin),
            [greenhouseResumeUploadException(s3.origin, RESUME)],
            [greenhousePresignRule(['https://job-boards.greenhouse.io'])],
        );
        const context = await browser.newContext();
        await guard.install(context);
        const page = await context.newPage();
        guard.setMainPage(page);
        await page.goto(`${server.origin}/greenhouse-upload.html`);
        guard.lockNavigation();
        await page.waitForLoadState('networkidle');
        await attach(page, guard);
        assert.equal(s3.uploads.length, 0);
        assert.match(
            guard.events.map((e) => e.detail).join('\n'),
            /not named by the page's own authorisation response/,
        );
        await context.close();
    });

    it('refuses when the destination the page was told differs from the exception origin', async () => {
        const { page, guard } = await open(`${other.origin}/`); // authorised, but not the origin the exception allows
        await attach(page, guard);
        assert.equal(s3.uploads.length, 0);
        assert.equal(
            other.uploads.length,
            0,
            'and nothing reached the other origin either',
        );
        assert.equal(guard.hasFailure(), true);
        await page.context().close();
    });

    it('refuses a page script that posts to the allowed origin at a different path', async () => {
        const { page, guard } = await open();
        guard.openUploadWindow();
        await page.evaluate(async (origin) => {
            const f = new FormData();
            f.append('key', 'k');
            f.append('file', new Blob(['x']), 'sample-resume.pdf');
            await fetch(`${origin}/elsewhere`, {
                method: 'POST',
                body: f,
            }).catch(() => 0);
        }, s3.origin);
        await page.waitForTimeout(300);
        guard.closeUploadWindow();
        assert.equal(s3.uploads.length, 0);
        await page.context().close();
    });

    it('refuses an upload outside the worker-initiated window', async () => {
        const { page, guard } = await open();
        await attach(page, guard, '#resume', RESUME_PATH, false);
        assert.equal(s3.uploads.length, 0);
        assert.match(
            guard.events.map((e) => e.detail).join('\n'),
            /not during a worker-initiated/,
        );
        await page.context().close();
    });

    it('refuses a differently NAMED file even to the authorised destination, in the window', async () => {
        const { page, guard } = await open();
        const dir = await mkdtemp(join(tmpdir(), 'gh-upload-'));
        const different = join(dir, 'other.pdf');
        writeFileSync(
            different,
            Buffer.concat([bytes, Buffer.from('\n% extra')]),
        );
        await attach(page, guard, '#resume', different);
        assert.equal(s3.uploads.length, 0);
        assert.match(
            guard.events.map((e) => e.detail).join('\n'),
            /does not carry the chosen resume's file name/,
        );
        await page.context().close();
    });

    it('KNOWN LIMITATION (documented, not a goal): a file with the right NAME but different bytes is not caught by the guard', async () => {
        // The guard sees an empty file part, so it cannot tell. Content identity is established by
        // hashing the file the browser holds at selection (prepare.ts), which the engine tests cover.
        const { page, guard } = await open();
        const dir = await mkdtemp(join(tmpdir(), 'gh-upload-'));
        const lookalike = join(dir, 'sample-resume.pdf');
        writeFileSync(
            lookalike,
            Buffer.concat([bytes, Buffer.from('\n% different bytes')]),
        );
        await attach(page, guard, '#resume', lookalike);
        assert.equal(
            s3.uploads.length,
            1,
            'allowed: the guard validated structure, name, destination, window and count',
        );
        assert.notEqual(
            s3.uploads[0]!.fileSha256,
            RESUME.sha256,
            'and the bucket really did receive different bytes',
        );
        await page.context().close();
    });

    it('refuses extra data smuggled in the upload (an unexpected field)', async () => {
        const { page, guard } = await open();
        guard.openUploadWindow();
        await page.evaluate(
            async ([origin, b64]) => {
                const raw = Uint8Array.from(atob(b64!), (c) => c.charCodeAt(0));
                const f = new FormData();
                f.append('key', 'k');
                f.append('applicant_email', 'someone@example.test');
                f.append('file', new Blob([raw]), 'sample-resume.pdf');
                await new Promise<void>((resolve) => {
                    const x = new XMLHttpRequest();
                    x.onloadend = () => resolve();
                    x.open('POST', `${origin}/`);
                    x.send(f);
                });
            },
            [s3.origin, bytes.toString('base64')] as const,
        );
        guard.closeUploadWindow();
        assert.equal(s3.uploads.length, 0);
        assert.match(
            guard.events.map((e) => e.detail).join('\n'),
            /unexpected form field name "applicant_email"/,
        );
        await page.context().close();
    });
});
