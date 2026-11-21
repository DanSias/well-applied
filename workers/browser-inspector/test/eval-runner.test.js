// The offline resolution batch runner. The core is tested with a FAKE resolver and a FAKE clock (no browser, no network, no files); the dry run uses the
// real resolver over local fixtures only. Nothing here makes a live request.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { sha256, stableJson } from '../eval/src/canon.js';
import { BatchRefused, runBatch, executionOrder } from '../eval/runner/batch.js';
import { sanitizeResolution, shapePath } from '../eval/runner/sanitize.js';
import { renderRun } from '../eval/runner/report.js';
import { startDryRun } from '../eval/runner/dry-run.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const DRY = path.join(ROOT, 'eval/runner/fixtures/dry-run-batch.json');

// ---- helpers -------------------------------------------------------------------------------------------------------------------------
const URLS = {};
const mkCase = (id, host = 'jobicy.com', extra = {}) => {
    URLS[id] = `http://${host}/jobs/${id.replace(/\D/g, '') || 1}`;

    return { id, source: host.split('.')[0], host, url_shape: `${host}/jobs/N`, url_sha256: sha256(URLS[id]), needs_tracker_permission: false, duplicate_group: null, hand_review_slot: false, ...extra };
};
const manifest = (cases, execution = {}, extra = {}) => {
    const m = {
        schema: 'eval-resolution-batch/1', status: 'proposed', purpose: 'test', source_cohort: { sha256: sha256('c') }, resolver: { allow_tracker_redirects: false },
        execution: { concurrency: 1, retries: 0, pacing: { min_ms_between_requests_to_the_same_host: 1000, min_ms_between_cases: 0 }, per_case: { max_pages: 3, max_follows: 2, max_redirects: 8, budget_ms: 1000 }, total_page_navigation_cap: 100, wall_clock_cap_minutes: 60, ...execution },
        stop_conditions: [], human_review: [], cases, ...extra,
    };
    const bytes = Buffer.from(JSON.stringify(m));

    return { m, bytes, sha: sha256(bytes) };
};
const fakeClock = () => {
    let t = 1_000_000;
    const sleeps = [];

    return { now: () => t, sleep: async (ms) => { sleeps.push(ms); t += ms; }, advance: (ms) => (t += ms), sleeps };
};
const res = (status, reason, extra = {}) => ({ schema: 'destination-resolution/1', status, reason, source: { host: 'jobicy.com' }, hops: [{ index: 0, requested: { host: 'jobicy.com' }, final: { host: 'jobicy.com' }, redirect_chain: [] }], apply_candidates: [], needs_human_review: false, review_reason: null, warnings: [], destination: null, ...extra });
const proposed = () => res('proposed_by_resolution', 'form_observed_after_following_apply_link', { hops: [0, 1, 2].map((i) => ({ index: i, requested: { host: 'jobicy.com' }, final: { host: 'jobicy.com' }, redirect_chain: [] })) });
const run = (cases, { resolve, execution, config, clock = fakeClock(), mode = 'dry-run', signal, onCheckpoint, getUrl } = {}) => {
    const { bytes, sha } = manifest(cases, execution);
    const calls = [];

    return runBatch({
        manifestBytes: bytes, expectSha256: sha, mode, config, clock, signal, onCheckpoint,
        getUrl: getUrl ?? (async (c) => URLS[c.id]),
        resolve: async (url, options) => { calls.push({ url, options }); return (resolve ?? (() => proposed()))(url, options, calls.length); },
    }).then((report) => Object.assign(report, { _calls: calls, _clock: clock }));
};
const state = (r, id) => r.cases.find((c) => c.id === id);

// ---- manifest hash, limits and tracker configuration ----------------------------------------------------------------------------------
describe('before anything runs', () => {
    test('the manifest hash is validated FIRST: a wrong hash refuses the run before the URL source or resolver is touched', async () => {
        const { bytes } = manifest([mkCase('c1')]);
        let touched = 0;
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: '0'.repeat(64), mode: 'dry-run', clock: fakeClock(), getUrl: async () => touched++, resolve: async () => touched++ }), (e) => e instanceof BatchRefused && e.code === 'manifest_hash_mismatch');
        assert.equal(touched, 0);
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: undefined, mode: 'plan', clock: fakeClock() }), (e) => e.code === 'manifest_hash_not_given');
    });

    test('a manifest is never an authorisation: it must be a proposal, and must not claim to be approved or to have run', async () => {
        for (const extra of [{ status: 'approved' }, { authorised: true }, { approved: true }, { run: {} }]) {
            const { bytes, sha } = manifest([mkCase('c1')], {}, extra);
            await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'plan', clock: fakeClock() }), (e) => e instanceof BatchRefused, JSON.stringify(extra));
        }
    });

    test('the manifest must itself carry the safe limits: concurrency 1, no retries, at most 3 pages per case, tracker redirects off', async () => {
        for (const [ex, code] of [[{ concurrency: 2 }, 'manifest_limits'], [{ retries: 1 }, 'manifest_limits'], [{ per_case: { max_pages: 4, budget_ms: 1 } }, 'manifest_limits'], [{ total_page_navigation_cap: 0 }, 'manifest_limits']]) {
            const { bytes, sha } = manifest([mkCase('c1')], ex);
            await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'plan', clock: fakeClock() }), (e) => e.code === code, JSON.stringify(ex));
        }
        const { bytes, sha } = manifest([mkCase('c1')], {}, { resolver: { allow_tracker_redirects: true } });
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'plan', clock: fakeClock() }), (e) => e.code === 'manifest_limits');
    });

    test('tracker redirects stay OFF by default, and a bare config flag cannot turn them on', async () => {
        const a = mkCase('a-track', 'www.adzuna.com', { needs_tracker_permission: true });
        const r = await run([a, mkCase('b')]);
        assert.deepEqual(r._calls.map((c) => c.options.allowTrackerRedirects), [false]);
        assert.deepEqual([state(r, 'a-track').state, state(r, 'a-track').reason], ['skipped', 'tracker_redirect_permission_not_granted']);
        assert.equal(r.config.allow_tracker_redirects, false);
        await assert.rejects(run([a], { config: { allow_tracker_redirects: true } }), (e) => e.code === 'tracker_not_approved');
        await assert.rejects(run([a], { config: { tracker_approval: { batch_sha256: 'x', case_ids: ['a-track'] } } }), (e) => e.code === 'tracker_not_approved');
    });

    test('tracker permission must name THIS batch hash and cases that actually need it; it then applies to those cases only', async () => {
        const a = mkCase('a-track', 'www.adzuna.com', { needs_tracker_permission: true });
        const b = mkCase('b-plain');
        const { bytes, sha } = manifest([a, b]);
        const go = (approval) => runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'dry-run', clock: fakeClock(), getUrl: async (c) => URLS[c.id], resolve: async (u, o) => { seen.push(o.allowTrackerRedirects); return proposed(); }, config: { allow_tracker_redirects: true, tracker_approval: approval } });
        const seen = [];
        await assert.rejects(go({ batch_sha256: 'f'.repeat(64), case_ids: ['a-track'] }), (e) => e.code === 'tracker_not_approved');
        await assert.rejects(go({ batch_sha256: sha, case_ids: ['b-plain'] }), (e) => e.code === 'tracker_not_approved');
        const r = await go({ batch_sha256: sha, case_ids: ['a-track'] });
        assert.deepEqual(seen, [false, true], 'b-plain runs first with it off; a-track runs last with it on');
        assert.deepEqual(r.config.tracker_approved_case_ids, ['a-track']);
    });

    test('an unknown mode is refused; live mode needs an authorization (the live adapter has its own tests); a dry run needs a resolver and a URL source', async () => {
        const { bytes, sha } = manifest([mkCase('c1')]);
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'bogus', clock: fakeClock(), getUrl: async () => 'x', resolve: async () => ({}) }), (e) => e.code === 'unknown_mode');
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'live', clock: fakeClock(), getUrl: async () => 'x', resolve: async () => ({}) }), (e) => e.code === 'authorization_missing');
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'dry-run', clock: fakeClock() }), (e) => e.code === 'no_resolver');
        await assert.rejects(runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'dry-run', authorization: {}, clock: fakeClock(), getUrl: async () => 'x', resolve: async () => ({}) }), (e) => e.code === 'authorization_unexpected');
        const plan = await runBatch({ manifestBytes: bytes, expectSha256: sha, mode: 'plan', clock: fakeClock() });
        assert.deepEqual([plan.totals.planned, plan.totals.attempted], [1, 0]);
    });
});

// ---- limits ----------------------------------------------------------------------------------------------------------------------------
describe('limits', () => {
    test('concurrency is 1: the resolver is never called while another call is in flight', async () => {
        let inFlight = 0;
        let max = 0;
        const r = await run(['c1', 'c2', 'c3', 'c4'].map((id, i) => mkCase(id, `h${i}.example.com`)), { resolve: async () => { inFlight++; max = Math.max(max, inFlight); await new Promise((x) => setTimeout(x, 5)); inFlight--; return proposed(); } });
        assert.equal(max, 1);
        assert.equal(r.totals.attempted, 4);
    });

    test('per-host pacing: a second request to the same host waits out the gap; a different host does not wait', async () => {
        const clock = fakeClock();
        await run([mkCase('c1', 'a.example.com'), mkCase('c2', 'a.example.com'), mkCase('c3', 'b.example.com')], { clock, resolve: () => res('unresolved', 'no_apply_link', { source: { host: 'a.example.com' }, hops: [{ index: 0, requested: { host: 'x' }, final: { host: 'x' }, redirect_chain: [] }] }) });
        assert.deepEqual(clock.sleeps, [1000], 'one wait, before the second a.example.com case only');
    });

    test('the destination host a case reached is also paced for later cases', async () => {
        const clock = fakeClock();
        const reached = () => res('unresolved', 'no_apply_link', { hops: [{ index: 0, requested: { host: 'jobicy.com' }, final: { host: 'employer.example.com' }, redirect_chain: [] }] });
        await run([mkCase('c1', 'jobicy.com'), mkCase('c2', 'employer.example.com')], { clock, resolve: reached });
        assert.deepEqual(clock.sleeps, [1000]);
    });

    test('NO retries: a failing case is attempted exactly once', async () => {
        const r = await run([mkCase('c1')], { resolve: () => { throw new Error('boom'); } });
        assert.equal(r._calls.length, 1);
        assert.equal(state(r, 'c1').reason, 'resolver_exception');
        const t = await run([mkCase('c1')], { resolve: () => res('unreachable', 'navigation_timeout') });
        assert.equal(t._calls.length, 1);
    });

    test('the total navigation cap stops the batch before a case that could exceed it, and records the rest as unattempted', async () => {
        const r = await run(['c1', 'c2', 'c3', 'c4'].map((id, i) => mkCase(id, `h${i}.example.com`)), { execution: { total_page_navigation_cap: 7 } });
        assert.equal(r.outcome.stop.cause, 'navigation_cap');
        assert.deepEqual(r.cases.map((c) => c.state), ['attempted', 'attempted', 'unattempted', 'unattempted']);
        assert.equal(r.totals.navigations, 6);
        assert.equal(state(r, 'c3').reason, 'navigation_cap_would_be_exceeded');
        assert.equal(state(r, 'c4').reason, 'batch_stopped:navigation_cap');
    });

    test('the wall-clock deadline stops the batch', async () => {
        const clock = fakeClock();
        const r = await run(['c1', 'c2', 'c3'].map((id, i) => mkCase(id, `h${i}.example.com`)), { clock, execution: { wall_clock_cap_minutes: 1 }, resolve: () => { clock.advance(40_000); return proposed(); } });
        assert.equal(r.outcome.stop.cause, 'wall_clock_cap');
        assert.deepEqual(r.cases.map((c) => c.state), ['attempted', 'attempted', 'unattempted']);
    });

    test('pacing that would run past the deadline stops the batch rather than waiting', async () => {
        const clock = fakeClock();
        const r = await run([mkCase('c1', 'a.example.com'), mkCase('c2', 'a.example.com')], { clock, execution: { wall_clock_cap_minutes: 1, pacing: { min_ms_between_requests_to_the_same_host: 120_000, min_ms_between_cases: 0 } } });
        assert.equal(r.outcome.stop.cause, 'wall_clock_cap');
        assert.equal(clock.sleeps.length, 0);
    });

    test('the per-case budget handed to the resolver comes from the manifest, and cases run in a fixed order with tracker cases last', async () => {
        const r = await run([mkCase('z-last'), mkCase('m-track', 'www.adzuna.com', { needs_tracker_permission: true }), mkCase('a-first')], { execution: { per_case: { max_pages: 3, max_follows: 2, max_redirects: 8, budget_ms: 4321 } } });
        assert.ok(r._calls.every((c) => c.options.budgetMs === 4321));
        assert.deepEqual(r.cases.map((c) => c.id), ['a-first', 'z-last', 'm-track']);
        assert.deepEqual(executionOrder(manifest([mkCase('b'), mkCase('a')]).m).map((c) => c.id), ['a', 'b']);
    });
});

// ---- stop conditions -------------------------------------------------------------------------------------------------------------------
describe('stop conditions end the WHOLE batch, and everything after is recorded as unattempted with its reason', () => {
    const three = () => ['c1', 'c2', 'c3'].map((id, i) => mkCase(id, `h${i}.example.com`));
    const stopsAt = async (resolveAt2, cause) => {
        const r = await run(three(), { resolve: (u, o, n) => (n === 2 ? resolveAt2 : proposed()) });
        assert.equal(r.outcome.stop.cause, cause);
        assert.equal(r.outcome.stop.at_case, 'c2');
        assert.equal(r._calls.length, 2, 'c3 was never attempted');
        assert.deepEqual([state(r, 'c3').state, state(r, 'c3').reason], ['unattempted', `batch_stopped:${cause}`]);

        return r;
    };

    test('a security refusal by policy (a private address, a disallowed host, the redirect limit)', async () => {
        await stopsAt(res('blocked', 'destination_prohibited', { blocked_by: 'policy', refused: 'private_address' }), 'security_refusal');
        await stopsAt(res('blocked', 'redirect_limit', { blocked_by: 'policy' }), 'security_refusal');
    });

    test('a prohibited method (a non-GET request attempted and blocked)', async () => {
        await stopsAt(res('blocked', 'destination_prohibited', { blocked_by: 'policy', refused: 'post_blocked' }), 'prohibited_method');
        await stopsAt(res('unresolved', 'no_apply_link', { warnings: ['blocked_non_get'] }), 'prohibited_method');
    });

    test('a changed source URL hash: that case is refused without calling the resolver, and the batch stops', async () => {
        const r = await run(three(), { getUrl: async (c) => (c.id === 'c2' ? `${URLS[c.id]}?changed=1` : URLS[c.id]) });
        assert.equal(r.outcome.stop.cause, 'source_url_hash_changed');
        assert.deepEqual([state(r, 'c2').state, state(r, 'c2').reason], ['refused', 'source_url_hash_changed']);
        assert.equal(r._calls.length, 1);
        assert.equal(state(r, 'c3').state, 'unattempted');
        const gone = await run(three(), { getUrl: async () => { throw new Error('db down'); } });
        assert.equal(gone.outcome.stop.cause, 'source_url_unavailable');
        assert.equal(gone._calls.length, 0);
    });

    test('two consecutive infrastructure failures; one failure followed by a success resets the count', async () => {
        const fail = () => res('unreachable', 'navigation_timeout');
        const r = await run(['c1', 'c2', 'c3', 'c4'].map((id, i) => mkCase(id, `h${i}.example.com`)), { resolve: () => fail() });
        assert.equal(r.outcome.stop.cause, 'infrastructure_failures');
        assert.equal(r._calls.length, 2);
        const ok = await run(['c1', 'c2', 'c3', 'c4'].map((id, i) => mkCase(id, `h${i}.example.com`)), { resolve: (u, o, n) => (n % 2 === 1 ? fail() : proposed()) });
        assert.equal(ok.outcome.stop, null, 'alternating failures never reach the threshold');
        assert.equal(ok._calls.length, 4);
    });

    test('an HTTP 404 or a sign-in wall is a RESULT, not an infrastructure failure', async () => {
        const r = await run(['c1', 'c2', 'c3'].map((id, i) => mkCase(id, `h${i}.example.com`)), { resolve: (u, o, n) => (n === 1 ? res('unreachable', 'http_404') : n === 2 ? res('login_required', 'login_wall_at_destination') : proposed()) });
        assert.equal(r.outcome.stop, null);
    });

    test('two blocked or rate-limited cases on one host stop THAT host only', async () => {
        const cs = [mkCase('c1', 'a.example.com'), mkCase('c2', 'a.example.com'), mkCase('c3', 'a.example.com'), mkCase('c4', 'b.example.com')];
        const r = await run(cs, { resolve: (u, o, n) => (u.includes('a.example.com') ? res('unreachable', 'http_429') : proposed()) });
        assert.deepEqual(r.cases.map((c) => [c.id, c.state, c.reason]), [['c1', 'attempted', 'http_429'], ['c2', 'attempted', 'http_429'], ['c3', 'skipped', 'host_stopped:a.example.com'], ['c4', 'attempted', 'form_observed_after_following_apply_link']]);
        assert.equal(r.outcome.stop, null);
    });

    test('three blocked cases overall stop the batch', async () => {
        const r = await run(['c1', 'c2', 'c3', 'c4'].map((id, i) => mkCase(id, `h${i}.example.com`)), { resolve: () => res('blocked', 'captcha', { blocked_by: 'site' }) });
        assert.equal(r.outcome.stop.cause, 'site_blocking_overall');
        assert.equal(r._calls.length, 3);
    });
});

// ---- interruption, checkpoints, records ------------------------------------------------------------------------------------------------
describe('interrupted runs, checkpoints and complete records', () => {
    test('an interrupt between cases stops the batch: finished cases are kept, the rest are unattempted with the reason', async () => {
        const ac = new AbortController();
        const r = await run(['c1', 'c2', 'c3', 'c4'].map((id, i) => mkCase(id, `h${i}.example.com`)), { signal: ac.signal, resolve: (u, o, n) => { if (n === 2) ac.abort(); return proposed(); } });
        assert.equal(r.outcome.stop.cause, 'interrupted');
        assert.deepEqual(r.cases.map((c) => c.state), ['attempted', 'interrupted', 'unattempted', 'unattempted'], 'the case in flight when the interrupt arrived is recorded as interrupted');
        assert.equal(state(r, 'c2').reason, 'interrupted_in_flight');
        assert.equal(state(r, 'c3').reason, 'batch_stopped:interrupted');
        assert.equal(r.outcome.completed, false);
    });

    test('a checkpoint is written after every case, so a crash loses at most the case in flight', async () => {
        const seen = [];
        await run(['c1', 'c2', 'c3'].map((id, i) => mkCase(id, `h${i}.example.com`)), { onCheckpoint: (rep) => seen.push(rep.cases.filter((c) => c.state === 'attempted').length) });
        assert.deepEqual(seen, [1, 2, 3, 3], 'after each case, then the final report');
    });

    test('a checkpoint taken mid-run already accounts for every case, finished or not', async () => {
        let mid;
        await run(['c1', 'c2', 'c3'].map((id, i) => mkCase(id, `h${i}.example.com`)), { onCheckpoint: (rep) => { mid ??= rep; } });
        assert.equal(mid.cases.length, 3);
        assert.deepEqual(mid.cases.map((c) => c.state), ['attempted', 'unattempted', 'unattempted']);
        assert.equal(mid.cases[1].reason, 'not_reached');
        assert.equal(mid.outcome.completed, false);
    });

    test('every case is accounted for exactly once: attempted + skipped + unattempted + refused (+ planned) = total', async () => {
        const r = await run([mkCase('c1'), mkCase('c2', 'a.example.com'), mkCase('c3', 'www.adzuna.com', { needs_tracker_permission: true }), mkCase('c4', 'b.example.com')], { resolve: (u, o, n) => (n === 2 ? res('blocked', 'destination_prohibited', { blocked_by: 'policy', refused: 'x' }) : proposed()) });
        const t = r.totals;
        assert.equal(t.attempted + t.skipped + t.unattempted + t.refused + t.planned + t.interrupted, t.cases);
        assert.ok(r.cases.every((c) => c.reason), 'every case carries a reason');
        assert.deepEqual(new Set(r.cases.map((c) => c.id)).size, 4);
    });

    test('per-case status, destination provenance, hop evidence, warnings and review needs are all recorded', async () => {
        const evidence = res('proposed_by_resolution', 'form_observed_after_following_apply_link', {
            source: { host: 'jobicy.com', host_type: 'aggregator', url: 'https://jobicy.com/jobs/123-senior-engineer-at-acme?utm=1' },
            destination: { url: 'https://boards.greenhouse.io/acme/jobs/999', host: 'boards.greenhouse.io', host_type: 'known_ats', platform: 'greenhouse', form_observed: true, basis: 'application_form_observed' },
            hops: [{ index: 0, requested: { url: 'https://jobicy.com/jobs/123-senior-engineer-at-acme', host: 'jobicy.com' }, final: { host: 'jobicy.com' }, http_status: 200, page_kind: 'listing', redirect_chain: [{ kind: 'start', host: 'jobicy.com', http_status: 200 }] }],
            needs_human_review: true, review_reason: 'check it', warnings: ['unknown_intermediate_redirect_host'],
        });
        const r = await run([mkCase('c1')], { resolve: () => evidence });
        const c = state(r, 'c1');
        assert.deepEqual([c.state, c.resolution.status, c.resolution.destination.platform, c.resolution.hops[0].page_kind, c.needs_human_review, c.warnings, c.navigations], ['attempted', 'proposed_by_resolution', 'greenhouse', 'listing', true, ['unknown_intermediate_redirect_host'], 1]);
        assert.deepEqual(r.review_queue, [{ case: 'c1', reasons: ['check it'] }]);
    });

    test('an unknown redirector is put in the review queue, never silently followed', async () => {
        const r = await run([mkCase('c1')], { resolve: () => res('unresolved', 'unknown_redirector_requires_review', { needs_human_review: true, review_reason: 'an unknown redirector', redirector: { where: 'apply_link', host: 'click.x.example', signals: ['tracking_host_label'] } }) });
        assert.equal(state(r, 'c1').needs_human_review, true);
        assert.ok(state(r, 'c1').review_reasons.includes('unknown_redirector_requires_review'));
        assert.equal(r.totals.needs_human_review, 1);
    });

    test('the same input gives the same report, byte for byte', async () => {
        const cs = ['c1', 'c2'].map((id, i) => mkCase(id, `h${i}.example.com`));
        assert.equal(stableJson(await run(cs).then(({ _calls, _clock, ...r }) => r)), stableJson(await run(cs).then(({ _calls, _clock, ...r }) => r)));
    });
});

// ---- what a committed report may contain -----------------------------------------------------------------------------------------------
describe('a committed report carries no query strings, ports, cookies, credentials, page text or public URL slugs', () => {
    test('sanitising reduces every URL to host and path shape, and anchor text to a plain "Apply…" or nothing', () => {
        const dirty = { url: 'https://himalayas.app/companies/airbnb/jobs/staff-software-engineer-passport?utm_source=x&token=abc#frag', hops: [{ requested: { url: 'http://jobs.example.com:8443/en-US/careers/job/1234567/senior-data-scientist' }, redirect_chain: [{ url: 'https://click.x.example/redirect?u=https%3A%2F%2Facme.com' }] }], apply_candidates: [{ text: 'Apply Now', url: 'https://x.example/apply' }, { text: 'Senior Staff Engineer at Acme', url: 'https://x.example/a' }], note: 'plain text stays' };
        const s = sanitizeResolution(dirty);
        assert.equal(s.url, 'himalayas.app/companies/<slug>/jobs/<slug>');
        assert.equal(s.hops[0].requested.url, 'jobs.example.com/en-us/careers/job/N/<slug>', 'no port, no slug');
        assert.equal(s.hops[0].redirect_chain[0].url, 'click.x.example/redirect');
        assert.deepEqual(s.apply_candidates.map((c) => c.text), ['Apply Now', '[omitted]']);
        assert.equal(s.note, 'plain text stays');
        assert.equal(/\?|#|:8443|airbnb|passport|token|senior-data/.test(JSON.stringify(s)), false);
        assert.equal(shapePath('/acme/11111111-1111-4111-8111-111111111111/apply'), '/<slug>/<id>/apply');
    });

    test('the run report is sanitised: the resolver\'s URLs never reach it with a query string, a slug or a port', async () => {
        const dirty = res('proposed_by_resolution', 'form_observed_after_following_apply_link', { source: { host: 'jobicy.com', url: 'http://jobicy.com:3000/jobs/123-staff-engineer?se=secret' }, destination: { url: 'https://acme.example/careers/staff-engineer-12345?gh_jid=1', host: 'acme.example' } });
        const r = await run([mkCase('c1', 'jobicy.com', { url_shape: 'himalayas.app/companies/hercules/jobs/staff-software-engineer-data' })], { resolve: () => dirty });
        const text = stableJson(r.cases);
        assert.equal(/secret|gh_jid|\?|:3000|staff-engineer|hercules|staff-software/.test(text), false, text);
        assert.equal(state(r, 'c1').source_shape, 'himalayas.app/companies/<slug>/jobs/<slug>');
    });
});

// ---- the dry run: the real resolver over local fixtures only ---------------------------------------------------------------------------
describe('the dry run (real resolver, local fixtures only)', () => {
    let env;
    const bytes = readFileSync(DRY);
    const sha = sha256(bytes);
    before(async () => { env = await startDryRun(); });
    after(() => env.close());
    const go = (b = bytes, s = sha, extra = {}) => runBatch({ manifestBytes: b, expectSha256: s, mode: 'dry-run', clock: { now: () => Date.now(), sleep: (ms) => new Promise((r) => setTimeout(r, ms)) }, resolve: env.resolve, getUrl: env.getUrl, ...extra });

    test('success, ambiguity, a login gate, an unknown redirector, no link and a dead destination are each recorded; a security refusal stops the batch', async () => {
        const r = await go();
        const got = Object.fromEntries(r.cases.map((c) => [c.id, [c.state, c.reason]]));
        assert.deepEqual(got, {
            'd-01-jobicy-success': ['attempted', 'form_observed_after_following_apply_link'],
            'd-02-ambiguous-links': ['attempted', 'multiple_apply_links'],
            'd-03-himalayas-login': ['attempted', 'login_wall_at_destination'],
            'd-04-direct-greenhouse': ['attempted', 'form_observed_at_source_url'],
            'd-05-unknown-redirector': ['attempted', 'unknown_redirector_requires_review'],
            'd-07-no-apply-link': ['attempted', 'no_apply_link'],
            'd-08-dead-destination': ['attempted', 'http_404'],
            'd-09-private-apply': ['attempted', 'destination_prohibited'],
            'd-10-after-the-stop': ['unattempted', 'batch_stopped:security_refusal'],
            'd-06-adzuna-tracker': ['unattempted', 'batch_stopped:security_refusal'],
        });
        assert.equal(r.outcome.stop.cause, 'security_refusal');
        assert.deepEqual(r.review_queue.map((q) => q.case), ['d-02-ambiguous-links', 'd-05-unknown-redirector']);
        assert.equal(r.totals.navigations, 12);
    });

    test('the tracker case is skipped, with its reason, when the batch runs to the end', async () => {
        const m = JSON.parse(bytes);
        m.cases = m.cases.filter((c) => !['d-09-private-apply', 'd-10-after-the-stop'].includes(c.id));
        const b = Buffer.from(JSON.stringify(m));
        const r = await go(b, sha256(b));
        assert.equal(r.outcome.completed, true);
        assert.deepEqual([state(r, 'd-06-adzuna-tracker').state, state(r, 'd-06-adzuna-tracker').reason], ['skipped', 'tracker_redirect_permission_not_granted']);
        assert.equal(env.seen.some(([site]) => site === 'adzuna' && false), false);
    });

    test('the Adzuna tracker is never requested unless it is approved by hash and case', async () => {
        const before = env.seen.filter(([s]) => s === 'adzuna').length;
        await go();
        assert.equal(env.seen.filter(([s]) => s === 'adzuna').length, before, 'no request reached the tracker host');
    });

    test('a changed source URL stops the dry run before the changed case, with the resolver untouched', async () => {
        const r = await go(bytes, sha, { getUrl: async (c) => (c.id === 'd-03-himalayas-login' ? `${c.fixture.logical_url}?x=1` : c.fixture.logical_url) });
        assert.equal(r.outcome.stop.cause, 'source_url_hash_changed');
        assert.equal(state(r, 'd-03-himalayas-login').state, 'refused');
        assert.ok(['d-04-direct-greenhouse', 'd-05-unknown-redirector', 'd-07-no-apply-link'].every((id) => state(r, id).state === 'unattempted'));
    });

    test('nothing but GET reached any fixture server, and the dry run refuses a host that is not a local fixture', async () => {
        assert.deepEqual([...new Set(env.seen.map(([, m]) => m))], ['GET']);
        await assert.rejects(env.resolve('http://real-site.example/job', {}), /not a local fixture/);
    });

    test('the committed dry-run report is current', async () => {
        const r = await go();
        const dir = path.join(ROOT, 'eval/runner/samples/dry-run');
        assert.equal(stableJson(r), readFileSync(path.join(dir, 'report.json'), 'utf8'), 'regenerate: node eval/runner/cli.js dry-run --manifest eval/runner/fixtures/dry-run-batch.json --expect-sha256 <hash> --out eval/runner/samples/dry-run');
        assert.equal(renderRun(r), readFileSync(path.join(dir, 'report.md'), 'utf8'));
        assert.equal(/\?|:\d{4,5}|cookie|authorization|token|"password"/i.test(readFileSync(path.join(dir, 'report.json'), 'utf8')), false);
    });
});

// ---- the CLI ---------------------------------------------------------------------------------------------------------------------------
describe('the CLI', () => {
    const cli = (...args) => spawnSync('node', [path.join(ROOT, 'eval/runner/cli.js'), ...args], { encoding: 'utf8' });
    const real = path.join(ROOT, 'eval/cohort/resolution-batch.proposed.json');
    const realSha = sha256(readFileSync(real));
    let dir;
    before(() => { dir = mkdtempSync(path.join(tmpdir(), 'runner-')); });
    after(() => rmSync(dir, { recursive: true, force: true }));

    test('plans the proposed 13-case batch without running anything: 8 would be attempted, the 5 Adzuna cases are skipped for want of tracker permission', () => {
        const r = cli('plan', '--manifest', real, '--expect-sha256', realSha, '--out', dir);
        assert.equal(r.status, 0, r.stderr);
        const rep = JSON.parse(readFileSync(path.join(dir, 'report.json'), 'utf8'));
        assert.deepEqual([rep.mode, rep.totals.planned, rep.totals.skipped, rep.totals.attempted, rep.totals.navigations], ['plan', 8, 5, 0, 0]);
        assert.equal(rep.config.allow_tracker_redirects, false);
        assert.deepEqual(readdirSync(dir).sort(), ['checkpoint.json', 'report.json', 'report.md', 'review-queue.json']);
    });

    test('refuses a wrong hash with exit 3 and writes nothing; refuses a missing hash and any "live" command with exit 2', () => {
        const d2 = mkdtempSync(path.join(tmpdir(), 'runner2-'));
        try {
            const r = cli('plan', '--manifest', real, '--expect-sha256', '1'.repeat(64), '--out', d2);
            assert.equal(r.status, 3);
            assert.match(r.stderr, /manifest_hash_mismatch/);
            assert.deepEqual(readdirSync(d2), []);
            assert.equal(cli('plan', '--manifest', real).status, 2);
            assert.equal(cli('live', '--manifest', real, '--expect-sha256', realSha).status, 2, 'live needs its own flags');
        } finally {
            rmSync(d2, { recursive: true, force: true });
        }
    });

    test('live from the CLI: refused without --supervised, refused with the template (granted: false), refused for a wrong hash, and none of them creates a run directory or touches a source', () => {
        const tpl = path.join(ROOT, 'eval/live/authorization.template.json');
        const pilot = path.join(ROOT, 'eval/cohort/resolution-pilot.proposed.json');
        const sha = sha256(readFileSync(pilot));
        const runs = () => (existsSync(path.join(ROOT, 'eval/runs')) ? readdirSync(path.join(ROOT, 'eval/runs')).filter((n) => n !== '.gitkeep' && n !== 'README.md') : []);
        const before = runs();
        const a = cli('live', '--manifest', pilot, '--expect-sha256', sha, '--authorization', tpl, '--source-db', '/nonexistent/db.sqlite');
        assert.equal(a.status, 2);
        assert.match(a.stderr, /--supervised/);
        const b = cli('live', '--manifest', pilot, '--expect-sha256', sha, '--authorization', tpl, '--source-db', '/nonexistent/db.sqlite', '--supervised');
        assert.equal(b.status, 3);
        assert.match(b.stderr, /authorization_not_granted/);
        const c = cli('live', '--manifest', pilot, '--expect-sha256', '2'.repeat(64), '--authorization', tpl, '--source-db', '/nonexistent/db.sqlite', '--supervised');
        assert.equal(c.status, 3);
        assert.match(c.stderr, /manifest_hash_mismatch/);
        assert.deepEqual(runs(), before);
        assert.equal(cli('plan', '--manifest', pilot, '--expect-sha256', sha, '--supervised').status, 2, 'plan and dry-run do not take live options');
    });

    test('the proposed Jobicy-only pilot validates and plans, excludes Himalayas, and the README quotes its hash', () => {
        const pilot = path.join(ROOT, 'eval/cohort/resolution-pilot-jobicy-only.proposed.json');
        const sha = sha256(readFileSync(pilot));
        const p = JSON.parse(readFileSync(pilot, 'utf8'));
        assert.deepEqual(p.cases.map((c) => c.source), ['jobicy', 'jobicy']);
        assert.deepEqual([p.execution.total_page_navigation_cap, p.execution.wall_clock_cap_minutes, p.execution.pacing.min_ms_between_requests_to_the_same_host], [6, 6, 10_000]);
        const d5 = mkdtempSync(path.join(tmpdir(), 'runner5-'));
        try {
            assert.equal(cli('plan', '--manifest', pilot, '--expect-sha256', sha, '--out', d5).status, 0);
            assert.equal(JSON.parse(readFileSync(path.join(d5, 'report.json'), 'utf8')).totals.planned, 2);
            assert.ok(readFileSync(path.join(ROOT, 'eval/runner/README.md'), 'utf8').includes(sha));
            assert.match(readFileSync(path.join(ROOT, 'eval/live/AUTOMATION-RESTRICTIONS.md'), 'utf8'), /pilot-jobicy-only/);
        } finally {
            rmSync(d5, { recursive: true, force: true });
        }
    });

    test('the proposed 3-case live pilot validates and plans: no tracker cases, tighter limits, and the README quotes its hash', () => {
        const pilot = path.join(ROOT, 'eval/cohort/resolution-pilot.proposed.json');
        const sha = sha256(readFileSync(pilot));
        const d4 = mkdtempSync(path.join(tmpdir(), 'runner4-'));
        try {
            assert.equal(cli('plan', '--manifest', pilot, '--expect-sha256', sha, '--out', d4).status, 0);
            const rep = JSON.parse(readFileSync(path.join(d4, 'report.json'), 'utf8'));
            assert.deepEqual([rep.totals.planned, rep.totals.skipped, rep.config.total_page_navigation_cap, rep.config.wall_clock_cap_minutes, rep.config.min_ms_between_requests_to_the_same_host], [3, 0, 9, 8, 10000]);
            const p = JSON.parse(readFileSync(pilot, 'utf8'));
            const batch = JSON.parse(readFileSync(real, 'utf8'));
            assert.equal(p.pilot_of.sha256, realSha);
            assert.ok(p.cases.every((c) => batch.cases.some((b) => stableJson(b) === stableJson(c)) && !c.needs_tracker_permission));
            assert.ok(readFileSync(path.join(ROOT, 'eval/runner/README.md'), 'utf8').includes(sha), 'the authorization text names the pilot hash');
        } finally {
            rmSync(d4, { recursive: true, force: true });
        }
    });

    test('the committed plan sample is current', () => {
        const d3 = mkdtempSync(path.join(tmpdir(), 'runner3-'));
        try {
            cli('plan', '--manifest', real, '--expect-sha256', realSha, '--out', d3);
            assert.equal(readFileSync(path.join(d3, 'report.json'), 'utf8'), readFileSync(path.join(ROOT, 'eval/runner/samples/plan/report.json'), 'utf8'));
        } finally {
            rmSync(d3, { recursive: true, force: true });
        }
    });
});
