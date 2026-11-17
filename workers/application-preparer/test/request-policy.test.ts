// Pure tests for the request rules. The real Lever origin appears here only
// as data in a matcher; nothing in this file makes a network request.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    LEVER_ORIGIN,
    lever,
    greenhouse,
    leverResumeParseException,
} from '../src/adapters/index.ts';
import {
    isCloudflareBotDetection,
    matchException,
    mediaType,
    type RequestFacts,
} from '../src/request-policy.ts';

const rule = leverResumeParseException(LEVER_ORIGIN);

const good = (
    over: Partial<RequestFacts> = {},
    url = 'https://jobs.lever.co/parseResume',
): RequestFacts => ({
    method: 'POST',
    url: new URL(url),
    resourceType: 'xhr',
    contentType: 'multipart/form-data; boundary=----WebKitFormBoundaryabc',
    bodyBytes: 631,
    isMainFrame: true,
    uploadWindowOpen: true,
    priorUses: 0,
    ...over,
});

describe('Lever resume-parse exception', () => {
    it('matches exactly the expected request', () => {
        assert.deepEqual(matchException(rule, good()), { matched: true });
        assert.deepEqual(
            matchException(rule, good({ resourceType: 'fetch' })),
            { matched: true },
        );
        assert.deepEqual(
            matchException(
                rule,
                good({ contentType: 'Multipart/Form-Data; boundary=x' }),
            ),
            { matched: true },
        );
    });

    describe('near-matching paths and URLs are all refused', () => {
        for (const url of [
            'https://jobs.lever.co/parseResume/',
            'https://jobs.lever.co/parseResume/extra',
            'https://jobs.lever.co/parseresume',
            'https://jobs.lever.co/PARSERESUME',
            'https://jobs.lever.co/parseResume2',
            'https://jobs.lever.co/xparseResume',
            'https://jobs.lever.co//parseResume',
            'https://jobs.lever.co/parseResume%2F',
            'https://jobs.lever.co/parseResume;a=1',
            'https://jobs.lever.co/acme/parseResume',
            'https://jobs.lever.co/acme/123/apply',
            'https://jobs.lever.co/',
            'https://jobs.lever.co/cdn-cgi/parseResume',
        ]) {
            it(`refuses ${url}`, () =>
                assert.equal(
                    matchException(rule, good({}, url)).matched,
                    false,
                ));
        }
    });

    describe('other origins are refused', () => {
        for (const url of [
            'http://jobs.lever.co/parseResume', // scheme
            'https://jobs.eu.lever.co/parseResume', // EU is not observed
            'https://lever.co/parseResume',
            'https://api.lever.co/parseResume',
            'https://jobs.lever.co.evil.example/parseResume',
            'https://evil.example/jobs.lever.co/parseResume',
            'https://jobs.lever.co:8443/parseResume',
            'https://boards.greenhouse.io/parseResume',
            'https://user:pw@jobs.lever.co/parseResume', // credentials
        ]) {
            it(`refuses ${url}`, () =>
                assert.equal(
                    matchException(rule, good({}, url)).matched,
                    false,
                ));
        }
    });

    describe('every other constraint is enforced, with a diagnosable near-miss', () => {
        const cases: Array<[string, Partial<RequestFacts>, string?, string?]> =
            [
                ['wrong method PUT', { method: 'PUT' }],
                ['wrong method DELETE', { method: 'DELETE' }],
                ['wrong method PATCH', { method: 'PATCH' }],
                [
                    'document (form-post navigation)',
                    { resourceType: 'document' },
                    undefined,
                    'resource type document',
                ],
                [
                    'ping/beacon',
                    { resourceType: 'ping' },
                    undefined,
                    'resource type ping',
                ],
                [
                    'JSON body',
                    { contentType: 'application/json' },
                    undefined,
                    'content-type application/json',
                ],
                [
                    'urlencoded body',
                    { contentType: 'application/x-www-form-urlencoded' },
                    undefined,
                    'content-type',
                ],
                [
                    'no content-type',
                    { contentType: null },
                    undefined,
                    'content-type absent',
                ],
                [
                    'outside a worker-initiated upload',
                    { uploadWindowOpen: false },
                    undefined,
                    'not during',
                ],
                ['second use', { priorUses: 1 }, undefined, 'already used'],
                [
                    'oversized body',
                    { bodyBytes: 13 * 1024 * 1024 },
                    undefined,
                    'too large',
                ],
                [
                    'unknown body size',
                    { bodyBytes: null },
                    undefined,
                    'too large',
                ],
                [
                    'from a child frame',
                    { isMainFrame: false },
                    undefined,
                    'child frame',
                ],
                [
                    'with a query string',
                    {},
                    'https://jobs.lever.co/parseResume?x=1',
                    'query string',
                ],
                [
                    'with a fragment',
                    {},
                    'https://jobs.lever.co/parseResume#x',
                    'fragment',
                ],
            ];
        for (const [name, over, url, nearMiss] of cases) {
            it(`refuses: ${name}`, () => {
                const r = matchException(rule, good(over, url));
                assert.equal(r.matched, false);
                if (nearMiss)
                    assert.match(
                        (r as { nearMiss: string }).nearMiss,
                        new RegExp(nearMiss),
                    );
            });
        }
    });

    it('is only ever granted for the exact Lever origin, and only by the Lever adapter', () => {
        const granted = lever.uploadExceptions(LEVER_ORIGIN);
        assert.equal(granted.length, 1);
        assert.deepEqual(
            {
                name: granted[0]!.name,
                method: granted[0]!.method,
                origin: granted[0]!.origin,
                path: granted[0]!.pathPattern.source,
            },
            {
                name: 'lever-resume-parse',
                method: 'POST',
                origin: 'https://jobs.lever.co',
                path: '^\\/parseResume$',
            },
        );
        for (const origin of [
            'https://jobs.eu.lever.co',
            'http://jobs.lever.co',
            'http://127.0.0.1:3000',
            'https://boards.greenhouse.io',
            'https://jobs.lever.co:8443',
        ]) {
            assert.deepEqual(lever.uploadExceptions(origin), [], origin);
        }
        assert.deepEqual(
            greenhouse.uploadExceptions('https://job-boards.greenhouse.io'),
            [],
        );
        assert.deepEqual(
            greenhouse.uploadExceptions(LEVER_ORIGIN),
            [],
            'Greenhouse never inherits it',
        );
    });

    it('does not authorise application submission endpoints or arbitrary POSTs', () => {
        for (const path of [
            '/example/0000/apply',
            '/apply',
            '/submit',
            '/applications',
            '/postings/x/apply',
            '/api/apply',
        ]) {
            assert.equal(
                matchException(rule, good({}, `https://jobs.lever.co${path}`))
                    .matched,
                false,
                path,
            );
        }
    });
});

describe('Cloudflare bot-detection classification', () => {
    const observed =
        'https://jobs.lever.co/cdn-cgi/challenge-platform/h/b/jsd/oneshot/f8df4d4a0cd6/0.06126049251609933:1794921329:yAzXRwFA4nFiX3ajOId711bNZSQLWwIsDGA1pJaP_DE/a5bf64858f23572e';

    it('recognises the observed shape on the page origin only', () => {
        assert.equal(
            isCloudflareBotDetection('POST', new URL(observed), LEVER_ORIGIN),
            true,
        );
        assert.equal(
            isCloudflareBotDetection('post', new URL(observed), LEVER_ORIGIN),
            true,
        );
    });

    describe('everything else is not recognised (and so still fails closed)', () => {
        const base =
            'https://jobs.lever.co/cdn-cgi/challenge-platform/h/b/jsd/oneshot/f8df4d4a0cd6/0.5:123:abc_DEF-9/a5bf64858f23572e';
        for (const [name, method, url, origin] of [
            ['GET is irrelevant here', 'GET', base, LEVER_ORIGIN],
            ['PUT', 'PUT', base, LEVER_ORIGIN],
            [
                'other cdn-cgi path',
                'POST',
                'https://jobs.lever.co/cdn-cgi/rum',
                LEVER_ORIGIN,
            ],
            [
                'other challenge-platform path',
                'POST',
                'https://jobs.lever.co/cdn-cgi/challenge-platform/h/b/orchestrate/jsch/v1',
                LEVER_ORIGIN,
            ],
            [
                'other jsd variant',
                'POST',
                'https://jobs.lever.co/cdn-cgi/challenge-platform/h/g/jsd/oneshot/f8df4d4a0cd6/0.5:123:abc/a5bf64858f23572e',
                LEVER_ORIGIN,
            ],
            ['extra trailing path', 'POST', `${base}/more`, LEVER_ORIGIN],
            ['trailing slash', 'POST', `${base}/`, LEVER_ORIGIN],
            [
                'prefixed path',
                'POST',
                base.replace('/cdn-cgi', '/x/cdn-cgi'),
                LEVER_ORIGIN,
            ],
            [
                'a different origin',
                'POST',
                base.replace('jobs.lever.co', 'evil.example'),
                LEVER_ORIGIN,
            ],
            [
                'page on a different origin',
                'POST',
                base,
                'https://jobs.eu.lever.co',
            ],
            [
                'short id',
                'POST',
                base.replace('f8df4d4a0cd6', 'f8df'),
                LEVER_ORIGIN,
            ],
            [
                'non-hex tail',
                'POST',
                base.replace('a5bf64858f23572e', 'zzzz64858f23572e'),
                LEVER_ORIGIN,
            ],
        ] as const) {
            it(`not recognised: ${name}`, () =>
                assert.equal(
                    isCloudflareBotDetection(method, new URL(url), origin),
                    false,
                ));
        }
    });
});

describe('mediaType', () => {
    it('drops parameters and case', () => {
        assert.equal(
            mediaType('Multipart/Form-Data; boundary=x'),
            'multipart/form-data',
        );
        assert.equal(mediaType(null), null);
        assert.equal(mediaType(''), null);
    });
});
