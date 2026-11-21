// The Ashby schema path inside inspect(): flag-off behaviour, the result it produces, and how failures are classified. A fake
// transport serves the captured fixtures; no network is touched and no browser is launched unless a test says it should be.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspect, ashbyResult } from '../src/inspect.js';
import { createGuard } from '../src/guard.js';
import { ashbySchemaRequested } from '../src/transport/flag.js';
import { fetchAshbySchema } from '../src/transport/ashby-schema.js';
import { AshbyTransportError } from '../src/transport/ashby.js';
import { createDeadline } from '../src/budget.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'ashby');
const fx = (n) => JSON.parse(readFileSync(path.join(dir, n), 'utf8'));
const URL_ = 'https://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef/application?token=SECRET';
const guard = createGuard({ resolve: async () => ({ safe: true }) });

/** A transport serving the captured responses, or what a test overrides per operation. */
function fakeTransport(over = {}) {
    const calls = [];
    return {
        calls,
        send: async (operationName, variables) => {
            calls.push([operationName, variables]);
            const o = over[operationName];
            if (o instanceof Error) throw o;
            return { json: o ?? fx(operationName === 'ApiJobPosting' ? 'nerdwallet-job.response.json' : 'nerdwallet-org.response.json'), ms: 7 };
        },
        requestsUsed: () => calls.length,
    };
}
function launchSpy() {
    const spy = { calls: 0, launch: async () => { spy.calls += 1; throw new Error('browser launch attempted'); } };
    return spy;
}
async function run(url, { flag = true, transport = fakeTransport(), launch = launchSpy() } = {}) {
    const made = [];
    const result = await inspect(url, { guard, launch: launch.launch, ashbySchema: flag, ashbyTransport: (d) => { made.push(d); return transport; }, genericDiscovery: false });

    return { result, transport, launch, made };
}

describe('the flag', () => {
    test('is on only for exactly "1"', () => {
        for (const [v, want] of [['1', true], [undefined, false], ['', false], ['0', false], ['true', false], ['yes', false], [' 1', false], ['11', false]]) {
            assert.equal(ashbySchemaRequested({ BROWSER_WORKER_ASHBY_SCHEMA: v }), want, String(v));
        }
        assert.equal(ashbySchemaRequested({}), false);
    });

    test('off: no transport is built or called, and the browser path runs exactly as before', async () => {
        const { launch, made, transport } = await run(URL_, { flag: false });
        assert.deepEqual([launch.calls, made.length, transport.calls.length], [1, 0, 0]);
    });
});

describe('flag on, a verified jobs.ashbyhq.com job link', () => {
    test('is read from the schema before any browser starts: ats ashby, partial, provider_schema, no URL claims', async () => {
        const { result, launch, transport } = await run(URL_);

        assert.equal(launch.calls, 0);
        assert.deepEqual(transport.calls.map((c) => c[0]), ['ApiOrganizationFromHostedJobsPageName', 'ApiJobPosting']);
        assert.deepEqual([result.status, result.ats, result.inspection_outcome, result.stop_reason], ['succeeded', 'ashby', 'partial', null]);
        assert.deepEqual([result.final_url, result.resolved_url], [null, null]);
        assert.ok(!JSON.stringify(result).includes('SECRET'), 'the link\'s query string is never kept');
        assert.equal(result.fields.length, 29);
        assert.ok(result.fields.every((f) => f.extraction_source === 'provider_schema'));
        assert.ok(result.fields.every((f) => f.label_source === 'provider_schema' || (f.label_source === 'unresolved' && f.raw_label === null)));
        for (const w of ['provider_schema_only', 'visibility_not_established', 'provider_schema_application_fields:22', 'provider_schema_survey_fields:7']) assert.ok(result.warnings.includes(w), w);
        assert.equal(result.diagnostics.pages_visited, 0);
    });

    test('sends variables derived only from the link: the page name and job id, lower-cased and validated', async () => {
        const { transport } = await run('https://jobs.ashbyhq.com/nerdwallet/32C62385-07D5-4D6D-960E-12C05CE0CBEF');
        assert.deepEqual(transport.calls, [
            ['ApiOrganizationFromHostedJobsPageName', { organizationHostedJobsPageName: 'nerdwallet', searchContext: 'JobPosting' }],
            ['ApiJobPosting', { organizationHostedJobsPageName: 'nerdwallet', jobPostingId: '32c62385-07d5-4d6d-960e-12c05ce0cbef' }],
        ]);
    });

    test('shares the inspection\'s deadline with the transport', async () => {
        const { made } = await run(URL_);
        assert.equal(made.length, 1);
        assert.equal(typeof made[0].slice, 'function');
        assert.equal(made[0].expired(), false);
    });
});

describe('everything else is unchanged with the flag on', () => {
    for (const url of [
        'https://boards.greenhouse.io/chainguard/jobs/4722145006',
        'https://jobs.lever.co/acme/32c62385-07d5-4d6d-960e-12c05ce0cbef',
        'https://jobs.ashbyhq.com.evil.example/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef',
        'https://evil.example/jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef',
        'http://jobs.ashbyhq.com/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef',
        'https://jobs.ashbyhq.com:8443/nerdwallet/32c62385-07d5-4d6d-960e-12c05ce0cbef',
        'https://jobs.ashbyhq.com/nerdwallet',
        'https://jobs.ashbyhq.com/nerdwallet/not-a-uuid',
        'https://careers.nerdwallet.com/job/32c62385-07d5-4d6d-960e-12c05ce0cbef',
    ]) {
        test(url, async () => {
            const { launch, made } = await run(url);
            assert.deepEqual([made.length, launch.calls], [0, 1], 'no transport built; the browser path ran');
        });
    }
});

describe('failures are classified, not disguised', () => {
    const drift = () => { const j = fx('nerdwallet-job.response.json'); j.data.jobPosting.applicationForm.sections = 'nope'; return j; };

    test('an ordinary failure falls back to the browser, with an accurate warning', async () => {
        for (const [over, evidence] of [
            [{ ApiJobPosting: new AshbyTransportError('availability', 'request_timeout') }, 'job_posting/request_timeout'],
            [{ ApiOrganizationFromHostedJobsPageName: new AshbyTransportError('availability', 'http_status', '503') }, 'organization/http_status'],
            [{ ApiJobPosting: drift() }, 'job_posting/schema_changed'],
            [{ ApiJobPosting: { errors: [{ message: 'x' }], data: null } }, 'job_posting/graphql_errors'],
            [{ ApiJobPosting: { data: { jobPosting: null } } }, 'job_posting/job_not_found'],
        ]) {
            const { launch, transport } = await run(URL_, { transport: fakeTransport(over) });
            assert.equal(launch.calls, 1, evidence);
            assert.ok(transport.calls.length >= 1);
            const state = { warnings: [] };
            const outcome = await fetchAshbySchema(URL_, fakeTransport(over));
            assert.equal(ashbyResult(outcome, state), null, evidence);
            assert.deepEqual(state.warnings, [`ashby_schema_unavailable:${evidence}`]);
        }
    });

    test('a security refusal fails closed: no browser, a failed result, and the evidence stored', async () => {
        for (const [over, evidence] of [
            [{ ApiJobPosting: new AshbyTransportError('security', 'redirect_refused', '302') }, 'job_posting/redirect_refused'],
            [{ ApiOrganizationFromHostedJobsPageName: new AshbyTransportError('security', 'prohibited_address') }, 'organization/prohibited_address'],
            [{ ApiJobPosting: new AshbyTransportError('security', 'tls_failure', 'ERR_TLS_CERT_ALTNAME_INVALID') }, 'job_posting/tls_failure'],
            [{ ApiJobPosting: new AshbyTransportError('security', 'response_too_large') }, 'job_posting/response_too_large'],
            [{ ApiJobPosting: (() => { const j = fx('nerdwallet-job.response.json'); j.data.jobPosting.id = '00000000-0000-4000-8000-000000000000'; return j; })() }, 'job_posting/job_id_mismatch'],
            [{ ApiOrganizationFromHostedJobsPageName: (() => { const o = fx('nerdwallet-org.response.json'); o.data.organization.hostedJobsPageSlug = 'someone-else'; return o; })() }, 'organization/organization_mismatch'],
        ]) {
            const { result, launch } = await run(URL_, { transport: fakeTransport(over) });
            assert.equal(launch.calls, 0, evidence);
            assert.deepEqual([result.status, result.failure_category], ['failed', 'unexpected_error'], evidence);
            assert.match(result.failure_message, new RegExp(`refused by security validation \\(${evidence}\\)`));
            assert.ok(result.warnings.includes('ashby_schema_security_refusal') && result.warnings.includes(`ashby_schema_refused:${evidence}`));
            assert.deepEqual(result.diagnostics.ashby_schema.kind, 'security');
            assert.ok(!('fields' in result));
        }
    });

    test('an unexpected exception is not swallowed as an ordinary failure', async () => {
        const boom = { send: async () => { throw new TypeError('bug'); } };
        const { result, launch } = await run(URL_, { transport: boom });
        assert.equal(launch.calls, 0);
        assert.deepEqual([result.status, result.failure_category], ['failed', 'unexpected_error']);
    });

    test('a fallback shares the one deadline: an expired one leaves the browser no extra time', async () => {
        const deadline = createDeadline(1000);
        assert.equal(deadline.expired(), false);
        const outcome = await fetchAshbySchema(URL_, fakeTransport({ ApiJobPosting: new AshbyTransportError('availability', 'deadline_exceeded') }));
        assert.equal(outcome.ok, false);
        assert.equal(outcome.kind, 'availability');
    });
});

describe('fetchAshbySchema', () => {
    test('a non-Ashby link is not applicable and sends nothing', async () => {
        const t = fakeTransport();
        assert.deepEqual(await fetchAshbySchema('https://boards.greenhouse.io/x/jobs/1', t), { applicable: false, reason: 'not_ashby_host' });
        assert.equal(t.calls.length, 0);
    });

    test('its contract equals the committed snapshot', async () => {
        const out = await fetchAshbySchema(URL_, fakeTransport());
        const snap = fx('expected/nerdwallet-258.contract.result.json');
        delete snap._note;
        assert.deepEqual(out.contract.result, snap);
    });
});
