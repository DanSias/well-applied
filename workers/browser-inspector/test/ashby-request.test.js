// The offline Ashby adapter's request side: which link it accepts, which documents it pins, and everything it refuses.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseAshbyJobUrl } from '../src/providers/ashby/target.js';
import { buildRequest, validateRequestBody, diagnoseDocument, AshbyRequestError, MAX_REQUEST_CHARS } from '../src/providers/ashby/request.js';
import { APPROVED_OPERATIONS, hashDocument, normaliseDocument } from '../src/providers/ashby/documents.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'ashby');
const captured = JSON.parse(readFileSync(path.join(dir, 'nerdwallet.requests.json'), 'utf8'));
const JOB = 'ApiJobPosting';
const ORG = 'ApiOrganizationFromHostedJobsPageName';
const ID = '32c62385-07d5-4d6d-960e-12c05ce0cbef';
const jobVars = () => ({ organizationHostedJobsPageName: 'nerdwallet', jobPostingId: ID });
const orgVars = () => ({ organizationHostedJobsPageName: 'nerdwallet', searchContext: 'JobPosting' });
const jobBody = (over = {}) => ({ operationName: JOB, variables: jobVars(), query: APPROVED_OPERATIONS[JOB].query, ...over });
const refusal = (body) => {
    const v = validateRequestBody(body);
    assert.equal(v.ok, false, `expected a refusal, got ${JSON.stringify(v).slice(0, 80)}`);

    return v;
};

describe('the pinned documents', () => {
    test('each approved document is exactly what the real page sent, and hashes to its pin', () => {
        for (const body of captured) {
            const approved = APPROVED_OPERATIONS[body.operationName];
            assert.ok(approved, body.operationName);
            assert.equal(hashDocument(body.query), approved.sha256);
            assert.equal(normaliseDocument(approved.query), normaliseDocument(body.query));
        }
        assert.deepEqual(Object.keys(APPROVED_OPERATIONS).sort(), [JOB, ORG]);
    });

    test('the pinned hashes are written down here too, so changing a document is a deliberate two-place edit', () => {
        assert.equal(APPROVED_OPERATIONS[JOB].sha256, 'd645729d845a97c5e15c3559886a74623209422be999b9a08914311cacdd164e');
        assert.equal(APPROVED_OPERATIONS[ORG].sha256, 'd63b13f85679eaac1d2418df77ce68334a1fe62e9ed8cdd9815b3fa598f982a9');
    });

    test('each is one query: no mutation, subscription, introspection, directive or comment', () => {
        for (const op of Object.values(APPROVED_OPERATIONS)) {
            assert.equal(op.type, 'query');
            assert.deepEqual(diagnoseDocument(op.query), []);
            assert.equal((op.query.match(/^\s*query\s/gm) ?? []).length, 1);
            assert.equal(/\b(mutation|subscription)\b|__schema|#/.test(op.query), false);
        }
    });

    test('the definitions are frozen, so nothing can widen them at runtime', () => {
        assert.throws(() => { APPROVED_OPERATIONS[JOB].sha256 = 'x'; }, TypeError);
        assert.throws(() => { APPROVED_OPERATIONS[JOB].variables.extra = {}; }, TypeError);
        assert.throws(() => { APPROVED_OPERATIONS.ApiSubmitApplicationFormAction = {}; }, TypeError);
    });
});

describe('the link it accepts', () => {
    test('accepts the application link and the posting link, with or without a trailing slash, ignoring query and fragment', () => {
        const want = { ok: true, organizationHostedJobsPageName: 'nerdwallet', jobPostingId: ID };
        for (const u of [
            `https://jobs.ashbyhq.com/nerdwallet/${ID}/application`,
            `https://jobs.ashbyhq.com/nerdwallet/${ID}`,
            `https://jobs.ashbyhq.com/nerdwallet/${ID}/`,
            `https://jobs.ashbyhq.com/nerdwallet/${ID}/application?utm_source=x#top`,
            `https://jobs.ashbyhq.com/nerdwallet/${ID.toUpperCase()}/application`,
        ]) assert.deepEqual(parseAshbyJobUrl(u), want, u);
    });

    for (const [reason, u] of [
        ['not_https', `http://jobs.ashbyhq.com/nerdwallet/${ID}`],
        ['not_ashby_host', `https://evil.example.com/nerdwallet/${ID}`],
        ['not_ashby_host', `https://jobs.ashbyhq.com.evil.example/nerdwallet/${ID}`],
        ['not_ashby_host', `https://ashbyhq.com/nerdwallet/${ID}`],
        ['not_ashby_host', `https://api.ashbyhq.com/nerdwallet/${ID}`],
        ['credentials_in_url', `https://user:pw@jobs.ashbyhq.com/nerdwallet/${ID}`],
        ['port_in_url', `https://jobs.ashbyhq.com:8443/nerdwallet/${ID}`],
        ['unexpected_path', 'https://jobs.ashbyhq.com/nerdwallet'],
        ['unexpected_path', `https://jobs.ashbyhq.com/nerdwallet/${ID}/application/extra`],
        ['unexpected_path', `https://jobs.ashbyhq.com/nerdwallet/${ID}/apply`],
        ['invalid_page_name', `https://jobs.ashbyhq.com/-bad/${ID}`],
        ['invalid_page_name', `https://jobs.ashbyhq.com/a%2Fb/${ID}`],
        ['invalid_job_posting_id', 'https://jobs.ashbyhq.com/nerdwallet/not-a-uuid'],
        ['not_a_url', 'nerdwallet'],
        ['not_a_url', ''],
        ['not_a_url', null],
        ['not_a_url', 42],
    ]) {
        test(`refuses ${reason}: ${String(u).slice(0, 60)}`, () => {
            assert.deepEqual(parseAshbyJobUrl(u), { ok: false, reason });
        });
    }
});

describe('a valid request', () => {
    test('is accepted for both approved operations, and buildRequest reproduces the captured bodies byte for byte', () => {
        assert.equal(validateRequestBody(jobBody()).ok, true);
        assert.equal(validateRequestBody({ operationName: ORG, variables: orgVars(), query: APPROVED_OPERATIONS[ORG].query }).ok, true);
        for (const body of captured) {
            assert.deepEqual(buildRequest(body.operationName, body.variables), { operationName: body.operationName, variables: body.variables, query: APPROVED_OPERATIONS[body.operationName].query });
            assert.equal(hashDocument(buildRequest(body.operationName, body.variables).query), hashDocument(body.query));
        }
    });

    test('tolerates only whitespace differences in the document', () => {
        assert.equal(validateRequestBody(jobBody({ query: `\n  ${APPROVED_OPERATIONS[JOB].query.replace(/ /g, '   ')}\n` })).ok, true);
    });

    test('buildRequest throws instead of returning anything unvalidated', () => {
        assert.throws(() => buildRequest('ApiSubmitApplicationFormAction', {}), (e) => e instanceof AshbyRequestError && e.reason === 'unknown_operation');
        assert.throws(() => buildRequest(JOB, { ...jobVars(), extra: 'x' }), (e) => e.reason === 'unexpected_variable');
        assert.throws(() => buildRequest(JOB, { organizationHostedJobsPageName: 'nerdwallet' }), (e) => e.reason === 'missing_variable');
    });
});

describe('what it refuses', () => {
    test('a batch array, however well formed its members', () => {
        assert.equal(refusal([jobBody()]).reason, 'batch_not_allowed');
        assert.equal(refusal([jobBody(), jobBody()]).reason, 'batch_not_allowed');
        assert.equal(refusal([]).reason, 'batch_not_allowed');
    });

    test('anything that is not one plain object', () => {
        for (const v of [null, undefined, 'query', 7, true, () => {}, new Map(), Object.create({ inherited: 1 })]) assert.equal(refusal(v).reason, 'not_an_object');
    });

    test('unexpected or missing body keys, including persisted-query extensions', () => {
        assert.equal(refusal({ ...jobBody(), extensions: { persistedQuery: { version: 1, sha256Hash: 'x' } } }).reason, 'unexpected_keys');
        assert.equal(refusal({ ...jobBody(), operations: [] }).reason, 'unexpected_keys');
        const { query, ...noQuery } = jobBody();
        assert.equal(refusal(noQuery).reason, 'unexpected_keys');
        assert.equal(refusal({ operationName: JOB, variables: jobVars() }).reason, 'unexpected_keys');
    });

    test('an operation name that is not approved, however read-only it sounds', () => {
        for (const name of ['ApiSubmitApplicationFormAction', 'ApiCreateFileUploadHandle', 'ApiSetFormValue', 'ApiJobBoardWithTeams', 'ApiJobPostingAppData', 'apijobposting', 'ApiJobPosting ', '', '__proto__', 'constructor', 'toString']) {
            assert.equal(refusal(jobBody({ operationName: name })).reason, 'unknown_operation', name);
        }
        assert.equal(refusal(jobBody({ operationName: 42 })).reason, 'unknown_operation');
    });

    test('a mutation disguised as an approved query: same operation name, a different document', () => {
        const disguised = 'mutation ApiJobPosting($organizationHostedJobsPageName: String!, $jobPostingId: String!) { submitApplicationFormAction(input: {jobPostingId: $jobPostingId}) { __typename } }';
        const v = refusal(jobBody({ query: disguised }));
        assert.equal(v.reason, 'document_not_pinned');
        assert.match(v.detail, /contains_mutation/);
    });

    test('a pinned query with a second operation appended', () => {
        const v = refusal(jobBody({ query: `${APPROVED_OPERATIONS[JOB].query}\nmutation ApiSetFormValue($x: String!) { setFormValue(x: $x) { __typename } }` }));
        assert.equal(v.reason, 'document_not_pinned');
        assert.match(v.detail, /multiple_operations/);
        assert.match(v.detail, /contains_mutation/);
        assert.equal(refusal(jobBody({ query: `${APPROVED_OPERATIONS[JOB].query}\nquery Other { __typename }` })).reason, 'document_not_pinned');
    });

    test('an approved document under the other approved operation name', () => {
        assert.equal(refusal({ operationName: ORG, variables: orgVars(), query: APPROVED_OPERATIONS[JOB].query }).reason, 'document_not_pinned');
        assert.equal(refusal({ operationName: JOB, variables: jobVars(), query: APPROVED_OPERATIONS[ORG].query }).reason, 'document_not_pinned');
    });

    test('an aliased, extended, commented, directive-carrying or introspecting variant of a pinned document', () => {
        const q = APPROVED_OPERATIONS[JOB].query;
        const variants = {
            alias_count_differs: q.replace('jobPosting(', 'stolen: jobPosting('),
            contains_comment: `# harmless\n${q}`,
            contains_directive: q.replace('applicationForm {', 'applicationForm @include(if: true) {'),
            contains_introspection: q.replace('recaptchaAction', 'recaptchaAction __schema { types { name } }'),
        };
        for (const [hint, variant] of Object.entries(variants)) {
            assert.notEqual(variant, q, hint);
            const v = refusal(jobBody({ query: variant }));
            assert.equal(v.reason, 'document_not_pinned', hint);
            assert.match(v.detail, new RegExp(hint), hint);
        }
        assert.equal(refusal(jobBody({ query: q.replace('recaptchaAction', 'recaptchaAction extraField') })).reason, 'document_not_pinned');
        assert.equal(refusal(jobBody({ query: q.replace('String!', 'String') })).reason, 'document_not_pinned');
    });

    test('a document that is not a string, or is oversized', () => {
        assert.equal(refusal(jobBody({ query: { kind: 'Document' } })).reason, 'document_not_a_string');
        assert.equal(refusal(jobBody({ query: null })).reason, 'document_not_a_string');
        assert.equal(refusal(jobBody({ query: 'q'.repeat(9000) })).reason, 'document_too_large');
    });

    test('variables that are not exactly the declared ones', () => {
        assert.equal(refusal(jobBody({ variables: null })).reason, 'variables_not_an_object');
        assert.equal(refusal(jobBody({ variables: [] })).reason, 'variables_not_an_object');
        assert.equal(refusal(jobBody({ variables: { ...jobVars(), first: 1 } })).reason, 'unexpected_variable');
        assert.equal(refusal(jobBody({ variables: { ...jobVars(), searchContext: 'JobPosting' } })).reason, 'unexpected_variable');
        assert.equal(refusal(jobBody({ variables: { jobPostingId: ID } })).reason, 'missing_variable');
        assert.equal(refusal(jobBody({ variables: {} })).reason, 'missing_variable');
        // a __proto__ key arrives as an own property from JSON.parse: it is an unexpected variable, not a prototype write
        assert.equal(refusal(jobBody({ variables: JSON.parse(`{"organizationHostedJobsPageName":"nerdwallet","jobPostingId":"${ID}","__proto__":{"x":1}}`) })).reason, 'unexpected_variable');
        assert.equal({}.x, undefined);
    });

    test('variables of the wrong type, shape or length', () => {
        const bad = (over) => refusal(jobBody({ variables: { ...jobVars(), ...over } }));
        for (const v of [123, null, ['nerdwallet'], { a: 1 }, true]) assert.equal(bad({ organizationHostedJobsPageName: v }).reason, 'invalid_variable');
        for (const v of ['', ' nerdwallet', 'nerd wallet', 'nerdwallet\n', '../x', 'a/b', 'a?b', 'ünï', '-x', 'a'.repeat(101), 'x"}, "y": "z']) assert.equal(bad({ organizationHostedJobsPageName: v }).reason, 'invalid_variable', JSON.stringify(v));
        for (const v of ['', 'not-a-uuid', ID.toUpperCase(), `${ID} `, `${ID}x`, ID.slice(0, -1), `${ID}\n`, '1; DROP', 7]) assert.equal(bad({ jobPostingId: v }).reason, 'invalid_variable', JSON.stringify(v));
        const o = (over) => refusal({ operationName: ORG, variables: { ...orgVars(), ...over }, query: APPROVED_OPERATIONS[ORG].query });
        for (const v of ['jobposting', 'Job', '', 'JobPosting ', null, 1]) assert.equal(o({ searchContext: v }).reason, 'invalid_variable', JSON.stringify(v));
    });

    test('a request over the size cap', () => {
        assert.ok(MAX_REQUEST_CHARS > 2116 + 200);
    });
});
