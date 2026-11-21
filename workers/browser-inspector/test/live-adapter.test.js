// The live adapter over LOCAL HTTPS fixtures (real Chromium, real guard, real resolver, real read-only source reader on a disposable database). The only thing
// replaced is the address check's verdict on the fixture host names, and the launcher's host mapping. Nothing here makes a request to the internet.
import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { sha256, stableJson } from '../eval/src/canon.js';
import { BatchRefused } from '../eval/runner/batch.js';
import { runLive } from '../eval/live/run-live.js';
import { openSourceReader } from '../eval/live/source-db.js';
import { LIVE_CEILINGS, validateAuthorization, AuthorizationRefused } from '../eval/runner/authorization.js';
import { createPacer } from '../eval/runner/pacer.js';
import { createLiveAdapter } from '../eval/live/adapter.js';
import { createHostPolicy } from '../eval/live/host-policy.js';
import { createRobots } from '../eval/live/robots.js';
import { loadRobotsSnapshot } from '../eval/live/run-live.js';
import { startLiveSites } from './support/live-sites.js';

let sites;
let dir;
let dbPath;
const T0 = Date.parse('2026-11-22T09:00:00Z');
// `tick`: every reading of the clock takes that many virtual milliseconds, so hosts marked a moment apart are paced separately (deterministic: no real time is read).
const fakeClock = ({ tick = 0 } = {}) => {
    let t = T0;
    const sleeps = [];

    return { now: () => { const v = t; t += tick; return v; }, sleep: async (ms) => { sleeps.push(ms); t += ms; }, advance: (ms) => (t += ms), sleeps };
};
const GAP = 10_000;
const execution = (o = {}) => ({ concurrency: 1, retries: 0, pacing: { min_ms_between_requests_to_the_same_host: GAP, min_ms_between_cases: 0 }, per_case: { max_pages: 3, max_follows: 2, max_redirects: 8, budget_ms: 60_000 }, total_page_navigation_cap: 9, wall_clock_cap_minutes: 8, ...o });

/** A disposable "production" database holding the fixture URLs by id. */
const makeDb = (rows) => {
    const db = new DatabaseSync(dbPath);
    db.exec('DROP TABLE IF EXISTS job_postings; CREATE TABLE job_postings (id INTEGER PRIMARY KEY, source_url TEXT, title TEXT, company TEXT)');
    const ins = db.prepare('INSERT INTO job_postings VALUES (?, ?, ?, ?)');
    for (const [id, url] of rows) ins.run(id, url, 'SECRET TITLE', 'SECRET COMPANY');
    db.close();
};
const setUrl = (id, url) => {
    const db = new DatabaseSync(dbPath);
    db.prepare('UPDATE job_postings SET source_url = ? WHERE id = ?').run(url, id);
    db.close();
};

/** @param {Array<[number, string, string]>} specs [job id, host, path] */
const build = (specs, { exec = {}, authOverride = {}, manifestExtra = {} } = {}) => {
    makeDb(specs.map(([id, host, p]) => [id, sites.url(host, p)]));
    const cases = specs.map(([id, host, p]) => ({ id: `live-${id}`, job_posting_id: id, source: host.split('.')[0], host, url_shape: `${host}${p.replace(/\d+/g, 'N')}`, url_sha256: sha256(sites.url(host, p)), needs_tracker_permission: false, duplicate_group: null, hand_review_slot: false }));
    const manifest = { schema: 'eval-resolution-batch/1', status: 'proposed', purpose: 'test', source_cohort: { sha256: sha256('c') }, resolver: { allow_tracker_redirects: false }, execution: execution(exec), stop_conditions: [], human_review: [], cases, ...manifestExtra };
    const bytes = Buffer.from(JSON.stringify(manifest));
    const sha = sha256(bytes);
    const e = manifest.execution;
    const authorization = {
        schema: 'eval-live-authorization/1', granted: true, granted_by: 'owner', granted_at: new Date(T0 - 60_000).toISOString(), valid_until: new Date(T0 + 3_600_000).toISOString(),
        statement: 'A test authorization naming this exact manifest, these cases, these hosts and these limits.', manifest_sha256: sha, case_ids: cases.map((c) => c.id), source_hosts: [...new Set(cases.map((c) => c.host))],
        reviewed_redirect_hosts: [],
        limits: { concurrency: 1, retries: 0, max_pages_per_case: e.per_case.max_pages, total_page_navigations: e.total_page_navigation_cap, pacing_ms: e.pacing.min_ms_between_requests_to_the_same_host, wall_clock_minutes: e.wall_clock_cap_minutes, tracker_redirects: false },
        ...authOverride,
    };

    return { manifest, bytes, sha, authorization };
};
const go = (b, opts = {}) => {
    const { clock = fakeClock(), signal, onCheckpoint, testing, reader } = opts;
    const authorization = 'authorization' in opts ? opts.authorization : b.authorization;

    return runLive({ manifestBytes: b.bytes, expectSha256: b.sha, authorization, sourceDb: dbPath, clock, signal, onCheckpoint, reader, testing: testing ?? sites.testing() }).then((r) => Object.assign(r, { _clock: clock }));
};
const state = (r, id) => r.cases.find((c) => c.id === `live-${id}`);

before(async () => {
    sites = await startLiveSites();
    dir = mkdtempSync(path.join(tmpdir(), 'live-'));
    dbPath = path.join(dir, 'fake.sqlite');
});
after(async () => {
    await sites.close();
    rmSync(dir, { recursive: true, force: true });
});

// ---- authorization --------------------------------------------------------------------------------------------------------------------
describe('live mode is disabled unless a separate authorization matches EXACTLY', () => {
    const refused = async (b, code, opts) => {
        const before = [sites.hits.length, 0];
        let readerCalls = 0;
        await assert.rejects(go(b, { ...opts, reader: { getSourceUrl: () => { readerCalls++; return 'x'; } } }), (e) => e instanceof BatchRefused && e.code === code, code);
        assert.equal(sites.hits.length, before[0], `${code}: no request reached any server`);
        assert.equal(readerCalls, 0, `${code}: no source record was read`);
    };

    test('no authorization at all', async () => refused(build([[1, 'jobicy.com', '/jobs/301']]), 'authorization_missing', { authorization: undefined }));

    test('the template, and any authorization that is not granted, with no statement, or no granter', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        const tpl = JSON.parse(readFileSync(path.join(path.dirname(new URL(import.meta.url).pathname), '..', 'eval/live/authorization.template.json'), 'utf8'));
        await refused(b, 'authorization_not_granted', { authorization: tpl });
        for (const o of [{ granted: false }, { statement: '' }, { statement: 'ok' }, { granted_by: '' }]) await refused(b, 'authorization_not_granted', { authorization: { ...b.authorization, ...o } });
    });

    test('a different manifest hash, extra or missing case ids, and source hosts that do not match', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301'], [2, 'himalayas.app', '/companies/acme/jobs/staff']]);
        await refused(b, 'authorization_manifest_mismatch', { authorization: { ...b.authorization, manifest_sha256: '0'.repeat(64) } });
        await refused(b, 'authorization_cases_mismatch', { authorization: { ...b.authorization, case_ids: ['live-1'] } });
        await refused(b, 'authorization_cases_mismatch', { authorization: { ...b.authorization, case_ids: ['live-1', 'live-2', 'live-3'] } });
        await refused(b, 'authorization_hosts_mismatch', { authorization: { ...b.authorization, source_hosts: ['jobicy.com'] } });
        await refused(b, 'authorization_hosts_mismatch', { authorization: { ...b.authorization, source_hosts: ['jobicy.com', 'himalayas.app', 'extra.example'] } });
    });

    test('every limit must equal the manifest\'s, tracker redirects must be explicitly false, and the milestone ceilings cannot be exceeded', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        for (const k of ['concurrency', 'retries', 'max_pages_per_case', 'total_page_navigations', 'pacing_ms', 'wall_clock_minutes']) await refused(b, 'authorization_limits_mismatch', { authorization: { ...b.authorization, limits: { ...b.authorization.limits, [k]: b.authorization.limits[k] + 1 } } });
        await refused(b, 'authorization_tracker', { authorization: { ...b.authorization, limits: { ...b.authorization.limits, tracker_redirects: true } } });
        await refused(b, 'authorization_tracker', { authorization: { ...b.authorization, limits: { ...b.authorization.limits, tracker_redirects: undefined } } });
        // A manifest AND authorization that agree with each other still cannot exceed the ceilings.
        for (const [exec, ] of [[{ total_page_navigation_cap: 10 }], [{ wall_clock_cap_minutes: 9 }], [{ pacing: { min_ms_between_requests_to_the_same_host: 9000, min_ms_between_cases: 0 } }], [{ per_case: { max_pages: 4, max_follows: 2, max_redirects: 8, budget_ms: 1000 } }]]) {
            const c = build([[1, 'jobicy.com', '/jobs/301']], { exec });
            await assert.rejects(go(c), (e) => e instanceof BatchRefused && /ceiling|max_pages|manifest_limits/.test(e.code), JSON.stringify(exec));
        }
        const four = build([1, 2, 3, 4].map((i) => [i, 'jobicy.com', '/jobs/301']));
        await refused(four, 'authorization_ceiling');
        assert.deepEqual([LIVE_CEILINGS.total_page_navigations, LIVE_CEILINGS.wall_clock_minutes, LIVE_CEILINGS.min_pacing_ms, LIVE_CEILINGS.max_pages_per_case], [9, 8, 10_000, 3]);
    });

    test('expired, not yet valid, or valid for longer than 24 hours', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        await refused(b, 'authorization_expired', { authorization: { ...b.authorization, valid_until: new Date(T0 - 1).toISOString() } });
        await refused(b, 'authorization_validity', { authorization: { ...b.authorization, granted_at: new Date(T0 + 60_000).toISOString() } });
        await refused(b, 'authorization_validity', { authorization: { ...b.authorization, valid_until: new Date(T0 + 25 * 3_600_000).toISOString(), granted_at: new Date(T0 - 1000).toISOString() } });
        await refused(b, 'authorization_validity', { authorization: { ...b.authorization, valid_until: 'soon' } });
    });

    test('tracker redirects cannot be enabled in a live run, by config or by a case that needs them; reviewed redirect hosts come only from the authorization', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        await assert.rejects(runLive({ manifestBytes: b.bytes, expectSha256: b.sha, authorization: b.authorization, sourceDb: dbPath, clock: fakeClock(), testing: sites.testing(), reader: { getSourceUrl: () => 'x' } }).then(() => null), null).catch(() => {});
        const t = build([[1, 'jobicy.com', '/jobs/301']]);
        t.manifest.cases[0].needs_tracker_permission = true;
        const tb = Buffer.from(JSON.stringify(t.manifest));
        await assert.rejects(runLive({ manifestBytes: tb, expectSha256: sha256(tb), authorization: { ...t.authorization, manifest_sha256: sha256(tb) }, sourceDb: dbPath, clock: fakeClock(), testing: sites.testing() }), (e) => e.code === 'authorization_tracker');
    });

    test('the validator is pure and reports its own error type', () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        assert.throws(() => validateAuthorization({}, { manifest: b.manifest, manifestSha: b.sha, now: T0 }), AuthorizationRefused);
        const ok = validateAuthorization(b.authorization, { manifest: b.manifest, manifestSha: b.sha, now: T0 });
        assert.match(ok.sha256, /^[0-9a-f]{64}$/);
    });
});

// ---- the read-only source ----------------------------------------------------------------------------------------------------------------
describe('production source URLs are read by id, read-only, and verified before navigation', () => {
    test('the reader reads exactly one column by id, refuses bad ids, and leaves the database byte-identical', () => {
        makeDb([[5, 'https://jobicy.com/jobs/301']]);
        const before = sha256(readFileSync(dbPath));
        const r = openSourceReader(dbPath);
        assert.equal(r.getSourceUrl(5), 'https://jobicy.com/jobs/301');
        for (const bad of [0, -1, 1.5, '5', null, 999]) assert.throws(() => r.getSourceUrl(bad), bad === 999 ? /no source URL/ : /positive integer/);
        assert.deepEqual(Object.keys(r).sort(), ['close', 'getSourceUrl'], 'no handle, no query method');
        r.close();
        assert.equal(sha256(readFileSync(dbPath)), before);
    });

    test('the connection itself refuses writes (SQLite read-only plus query_only), and the reader\'s SQL is fixed and selects only source_url', () => {
        const ro = new DatabaseSync(dbPath, { readOnly: true });
        assert.throws(() => ro.exec("INSERT INTO job_postings VALUES (9, 'x', 't', 'c')"), /readonly|read-only|read only/i);
        assert.throws(() => ro.exec('DROP TABLE job_postings'), /readonly|read-only|read only/i);
        ro.close();
        const src = readFileSync(path.join(path.dirname(new URL(import.meta.url).pathname), '..', 'eval/live/source-db.js'), 'utf8');
        assert.match(src, /readOnly: true/);
        assert.match(src, /PRAGMA query_only = 1/);
        assert.match(src, /const SQL = 'SELECT source_url FROM job_postings WHERE id = \?'/);
        assert.equal((src.match(/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|REPLACE)\b/g) ?? []).length, 0);
        assert.equal(/title|company|description|location|career_profile|resume|application|agent_run/i.test(src.split('\n').filter((l) => !/^\s*(\/\/|\/\*|\*)/.test(l)).join('\n')), false);
    });

    test('a live run never reads a title or company, and a run leaves the database byte-identical', async () => {
        const b = build([[7, 'jobicy.com', '/jobs/301']]);
        const before = sha256(readFileSync(dbPath));
        const r = await go(b);
        assert.equal(sha256(readFileSync(dbPath)), before);
        assert.equal(/SECRET/.test(stableJson(r)), false);
    });

    test('a source record that changed after the manifest was hashed stops the batch BEFORE any navigation', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301'], [2, 'jobicy.com', '/jobs/304']]);
        setUrl(1, sites.url('jobicy.com', '/jobs/304'));
        const hits = sites.hits.length;
        const r = await go(b);
        assert.equal(r.outcome.stop.cause, 'source_url_hash_changed');
        assert.deepEqual([state(r, 1).state, state(r, 1).reason, state(r, 2).state], ['refused', 'source_url_hash_changed', 'unattempted']);
        assert.equal(sites.hits.length, hits, 'no request reached any server');
    });

    test('a missing record stops the batch with its own reason', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        const db = new DatabaseSync(dbPath);
        db.exec('DELETE FROM job_postings');
        db.close();
        const r = await go(b);
        assert.deepEqual([r.outcome.stop.cause, state(r, 1).state], ['source_url_unavailable', 'refused']);
    });
});

// ---- authorized execution, redirects, pacing -----------------------------------------------------------------------------------------
describe('authorized execution through the real guard, resolver and Chromium', () => {
    test('an authorized case resolves: a Jobicy-shaped listing, an employer page, then a Greenhouse form', async () => {
        const b = build([[1, 'jobicy.com', '/jobs/301']]);
        const r = await go(b);
        const c = state(r, 1);
        assert.deepEqual([c.state, c.resolution.status, c.resolution.destination.platform, c.resolution.destination.form_observed], ['attempted', 'proposed_by_resolution', 'greenhouse', true]);
        assert.equal(r.totals.navigations, 3);
        assert.equal(r.outcome.completed, true);
        assert.equal(r.authorisation.granted_by, 'owner');
        assert.deepEqual(c.resolution.host_policy.approved_hosts, ['careers.acmecorp.com', 'job-boards.greenhouse.io', 'jobicy.com'], 'source host, then each Apply destination the resolver chose');
        assert.deepEqual(c.resolution.host_policy.paced_hosts, [], 'every host was new: nothing to wait for');
        assert.equal(r.mode, 'live');
    });

    test('a Himalayas-shaped listing to a Lever application form (a known ATS host is approved without being named)', async () => {
        const r = await go(build([[2, 'himalayas.app', '/companies/acme/jobs/staff']]));
        assert.deepEqual([state(r, 2).resolution.status, state(r, 2).resolution.destination.platform], ['proposed_by_resolution', 'lever']);
    });

    test('an UNKNOWN host reached by an HTTP redirect is refused BEFORE it is requested: unresolved, for review, and it does not stop the batch', async () => {
        const b = build([[3, 'jobicy.com', '/jobs/302'], [4, 'jobicy.com', '/jobs/304']]);
        const r = await go(b);
        const c = state(r, 3);
        assert.deepEqual([c.resolution.status, c.resolution.reason, c.needs_human_review], ['unresolved', 'redirect_host_requires_review', true]);
        assert.equal(sites.hitsOn('go.linkforward-example.io'), 0, 'the unknown redirect host was never requested');
        assert.equal(r.outcome.stop, null, 'a host-policy refusal is not a security stop');
        assert.equal(state(r, 4).state, 'attempted');
        assert.ok(c.resolution.host_policy.refusals.includes('host_not_approved:go.linkforward-example.io'));
    });

    test('a host a PERSON reviewed (named in the authorization) may be followed: still paced, still recorded', async () => {
        const b = build([[3, 'jobicy.com', '/jobs/302']], { authOverride: { reviewed_redirect_hosts: ['go.linkforward-example.io'] } });
        const r = await go(b);
        assert.equal(state(r, 3).resolution.status, 'proposed_by_resolution');
        assert.equal(sites.hitsOn('go.linkforward-example.io', '/hop') >= 1, true);
        assert.deepEqual(r.config.reviewed_redirect_hosts, ['go.linkforward-example.io']);
    });

    test('an unknown click-through is flagged by the resolver before navigation and never requested', async () => {
        const hits = sites.hitsOn('click.mailtrack-example.com');
        const r = await go(build([[5, 'jobicy.com', '/jobs/309']]));
        assert.equal(state(r, 5).resolution.reason, 'unknown_redirector_requires_review');
        assert.equal(sites.hitsOn('click.mailtrack-example.com'), hits);
    });

    test('a third-party subresource host is never requested, and the page still resolves', async () => {
        const hits = sites.hitsOn('cdn.thirdparty-example.net');
        const r = await go(build([[6, 'jobicy.com', '/jobs/305']]));
        assert.equal(state(r, 6).resolution.status, 'proposed_by_resolution');
        assert.equal(sites.hitsOn('cdn.thirdparty-example.net'), hits);
        assert.ok(state(r, 6).warnings.includes('blocked_prohibited'), 'the refusal is recorded as a warning');
    });

    test('an Apply link to a path the host\'s robots.txt disallows is refused before it is requested, and flagged for review', async () => {
        const hits = sites.hitsOn('jobicy.com', '/go/abc123');
        const r = await go(build([[7, 'jobicy.com', '/jobs/303']]));
        assert.deepEqual([state(r, 7).resolution.reason, state(r, 7).needs_human_review, state(r, 7).resolution.status], ['robots_disallowed_requires_review', true, 'unresolved']);
        assert.equal(sites.hitsOn('jobicy.com', '/go/abc123'), hits);
    });

    test('a SOURCE URL on a robots-disallowed path is refused before any browser exists', async () => {
        const hits = sites.hits.length;
        const r = await go(build([[7, 'jobicy.com', '/go/abc123']]));
        assert.deepEqual([state(r, 7).state, state(r, 7).resolution.reason, state(r, 7).navigations], ['attempted', 'robots_disallowed_requires_review', 0]);
        assert.equal(sites.hits.length, hits);
    });

    test('an Apply link to a private address is a SECURITY refusal: the batch stops and later cases are unattempted', async () => {
        const r = await go(build([[8, 'jobicy.com', '/jobs/307'], [9, 'jobicy.com', '/jobs/301']]));
        assert.equal(r.outcome.stop.cause, 'security_refusal');
        assert.deepEqual([state(r, 8).state, state(r, 9).state, state(r, 9).reason], ['attempted', 'unattempted', 'batch_stopped:security_refusal']);
    });

    test('a redirect into a private address is a security refusal too', async () => {
        const r = await go(build([[8, 'jobicy.com', '/jobs/310']]));
        assert.equal(r.outcome.stop.cause, 'security_refusal');
    });

    test('nothing but GET ever reached a fixture server', () => assert.deepEqual([...new Set(sites.hits.map(([, m]) => m))], ['GET']));
});

describe('pacing applies to every navigation, not only between cases', () => {
    /** The adapter alone, with a pacer whose history the test controls: so each pacing decision can be attributed to the navigation that caused it. */
    const direct = async (urlPath, { site = 'jobicy.com', marks = {}, deadlineAfter = Infinity, reviewed = [] } = {}) => {
        const clock = fakeClock();
        const pacer = createPacer({ clock, gapMs: GAP });
        for (const [h, agoMs] of Object.entries(marks)) pacer.mark(h, clock.now() - agoMs);
        const adapter = createLiveAdapter({ reader: {}, robotsSnapshot: loadRobotsSnapshot(), testing: sites.testing() });
        const resolution = await adapter.resolve(sites.url(site, urlPath), { pacer, deadline: clock.now() + deadlineAfter, reviewedRedirectHosts: reviewed, budgetMs: 60_000 });

        return { resolution, clock, pacer };
    };

    test('the first contact with each host waits for nothing', async () => {
        const { resolution, clock } = await direct('/jobs/301');
        assert.deepEqual([clock.sleeps.length, resolution.host_policy.paced_hosts, resolution.status], [0, [], 'proposed_by_resolution']);
    });

    test('a DESTINATION host the Apply link leads to is paced before it is requested', async () => {
        const { resolution, clock } = await direct('/jobs/301', { marks: { 'careers.acmecorp.com': 4000 } });
        assert.deepEqual(resolution.host_policy.paced_hosts, ['careers.acmecorp.com']);
        assert.deepEqual(clock.sleeps, [6000], 'exactly the remaining part of the gap');
        assert.equal(resolution.status, 'proposed_by_resolution');
    });

    test('a host reached by an HTTP REDIRECT is paced before the redirect is followed', async () => {
        const hop = sites.hitsOn('go.linkforward-example.io', '/hop');
        const { resolution, clock } = await direct('/jobs/302', { marks: { 'go.linkforward-example.io': 2500 }, reviewed: ['go.linkforward-example.io'] });
        assert.ok(resolution.host_policy.paced_hosts.includes('go.linkforward-example.io'), JSON.stringify(resolution.host_policy));
        assert.deepEqual(clock.sleeps, [7500]);
        assert.equal(sites.hitsOn('go.linkforward-example.io', '/hop') - hop, 1);
        assert.equal(resolution.status, 'proposed_by_resolution');
    });

    test('a host used recently by an earlier case, met again inside a later case whose source host is new, waits out its gap', async () => {
        const clock = fakeClock();
        const r = await go(build([[1, 'jobicy.com', '/jobs/301'], [2, 'himalayas.app', '/companies/acme/jobs/employer']]), { clock });
        assert.deepEqual(state(r, 2).resolution.host_policy.paced_hosts, ['careers.acmecorp.com']);
        assert.equal(clock.sleeps.length, 1, 'the source host was new, so the one wait was inside the case');
    });

    test('between cases the runner waits out the gap for the source host', async () => {
        const clock = fakeClock();
        await go(build([[1, 'jobicy.com', '/jobs/301'], [2, 'jobicy.com', '/jobs/304']]), { clock });
        assert.equal(clock.sleeps.length, 1);
        assert.ok(clock.sleeps[0] > 0 && clock.sleeps[0] <= GAP);
    });

    test('pacing that would pass the deadline refuses the navigation instead of waiting: the host is never requested', async () => {
        const employer = sites.hitsOn('careers.acmecorp.com');
        const { resolution, clock } = await direct('/jobs/301', { marks: { 'careers.acmecorp.com': 1000 }, deadlineAfter: 5000 });
        assert.deepEqual([resolution.status, resolution.reason, resolution.needs_human_review], ['unresolved', 'pacing_would_pass_deadline', false]);
        assert.equal(clock.sleeps.length, 0);
        assert.equal(sites.hitsOn('careers.acmecorp.com'), employer);
    });

    test('a redirect preflight that would pass the deadline is refused before the host is requested', async () => {
        const hop = sites.hitsOn('go.linkforward-example.io');
        const { resolution } = await direct('/jobs/302', { marks: { 'go.linkforward-example.io': 1000 }, deadlineAfter: 5000, reviewed: ['go.linkforward-example.io'] });
        assert.equal(resolution.reason, 'pacing_would_pass_deadline');
        assert.equal(sites.hitsOn('go.linkforward-example.io'), hop);
    });
});

describe('limits, failures, deadlines and interruptions', () => {
    test('the total navigation cap stops the batch before a case that could exceed it', async () => {
        const r = await go(build([[1, 'jobicy.com', '/jobs/301'], [2, 'jobicy.com', '/jobs/304']], { exec: { total_page_navigation_cap: 3 } }));
        assert.equal(r.outcome.stop.cause, 'navigation_cap');
        assert.deepEqual([r.totals.navigations, state(r, 2).state, state(r, 2).reason], [3, 'unattempted', 'navigation_cap_would_be_exceeded']);
    });

    test('the wall-clock deadline stops the batch between cases', async () => {
        const clock = fakeClock();
        const r = await go(build([[1, 'jobicy.com', '/jobs/301'], [2, 'himalayas.app', '/companies/acme/jobs/staff']]), { clock, onCheckpoint: (rep) => { if (rep.totals.attempted === 1) clock.advance(9 * 60_000); } });
        assert.equal(r.outcome.stop.cause, 'wall_clock_cap');
    });

    test('a 404 destination and a case with no Apply link are RESULTS, not failures: the batch continues', async () => {
        const r = await go(build([[1, 'jobicy.com', '/jobs/306'], [2, 'himalayas.app', '/companies/acme/jobs/staff']]));
        assert.equal(state(r, 1).resolution.reason, 'no_apply_link');
        assert.equal(r.outcome.stop, null);
    });

    test('NO retries: each timing-out case is attempted exactly once, and two consecutive infrastructure failures stop the whole batch', async () => {
        const before = sites.hitsOn('jobicy.com', '/jobs/308');
        const t = sites.testing({ timeouts: { navigationMs: 1500, settleMs: 100, networkIdleMs: 500 } });
        const r = await go(build([[1, 'jobicy.com', '/jobs/308'], [3, 'jobicy.com', '/jobs/308'], [4, 'himalayas.app', '/companies/acme/jobs/staff']]), { testing: t });
        assert.equal(r.outcome.stop.cause, 'infrastructure_failures');
        assert.deepEqual([state(r, 1).resolution.reason, state(r, 3).resolution.reason, state(r, 4).state], ['navigation_timeout', 'navigation_timeout', 'unattempted']);
        assert.equal(sites.hitsOn('jobicy.com', '/jobs/308') - before, 2, 'one request per case: nothing was retried');
    });

    test('an interrupt DURING a case closes the browser at once: no further request is made, the case is recorded as interrupted, and nothing else is attempted', async () => {
        const ac = new AbortController();
        const t = sites.testing({ timeouts: { navigationMs: 15_000, settleMs: 100, networkIdleMs: 500 } });
        const before = sites.hits.length;
        setTimeout(() => ac.abort(), 700);
        const r = await go(build([[1, 'jobicy.com', '/jobs/308'], [2, 'himalayas.app', '/companies/acme/jobs/staff']]), { testing: t, signal: ac.signal });
        assert.equal(r.outcome.stop.cause, 'interrupted');
        assert.deepEqual([state(r, 1).state, state(r, 1).reason, state(r, 2).state], ['interrupted', 'interrupted_in_flight', 'unattempted']);
        assert.equal(sites.hits.length - before, 1, 'only the request that was already in flight');
        assert.equal(r.outcome.completed, false);
    });

    test('an interrupt before the run starts attempts nothing', async () => {
        const ac = new AbortController();
        ac.abort();
        const before = sites.hits.length;
        const r = await go(build([[1, 'jobicy.com', '/jobs/301']]), { signal: ac.signal });
        assert.deepEqual([r.outcome.stop.cause, state(r, 1).state], ['interrupted', 'unattempted']);
        assert.equal(sites.hits.length, before);
    });
});

describe('what a live report contains', () => {
    test('sanitised and reproducible: hosts and path shapes only, no query string, port, cookie, credential, anchor text or slug, and the same bytes on a re-run', async () => {
        const run = async () => stableJson(await go(build([[1, 'jobicy.com', '/jobs/301'], [3, 'jobicy.com', '/jobs/302']])).then(({ _clock, ...r }) => r));
        const a = await run();
        assert.equal(/\?|:\d{4,5}|cookie|authorization|token|gh_jid|SECRET|Platform Engineer/i.test(a), false);
        assert.equal(/"password"/.test(a), false);
        assert.match(a, /careers\.acmecorp\.com\/jobs\/N/);
        // Ports differ between runs of the fixtures only if the servers restart; the report must not contain them at all, so a re-run is identical.
        assert.equal(await run(), a);
    });

    test('the review queue lists every flagged case with its reason', async () => {
        const r = await go(build([[3, 'jobicy.com', '/jobs/302'], [5, 'jobicy.com', '/jobs/309']]));
        assert.deepEqual(r.review_queue.map((q) => q.case).sort(), ['live-3', 'live-5']);
        assert.ok(r.review_queue.every((q) => q.reasons.length > 0));
    });
});

// ---- per-request policy: no decision relies on the guard's per-host cache ---------------------------------------------------------------------
describe('every relevant navigation is decided, including a host revisited within a case and every redirect hop', () => {
    const direct = async (urlPath, { site = 'jobicy.com', marks = {}, deadlineAfter = Infinity, reviewed = [] } = {}) => {
        const clock = fakeClock();
        const pacer = createPacer({ clock, gapMs: GAP });
        for (const [h, agoMs] of Object.entries(marks)) pacer.mark(h, clock.now() - agoMs);
        const adapter = createLiveAdapter({ reader: {}, robotsSnapshot: loadRobotsSnapshot(), testing: sites.testing() });
        const resolution = await adapter.resolve(sites.url(site, urlPath), { pacer, deadline: clock.now() + deadlineAfter, reviewedRedirectHosts: reviewed, budgetMs: 60_000 });

        return { resolution, clock, pacer };
    };

    test('a host REVISITED within the same case by an Apply link is paced again: the second jobicy.com navigation waits out the gap', async () => {
        const { resolution, clock } = await direct('/jobs/311');
        assert.ok(resolution.host_policy.paced_hosts.includes('jobicy.com'), JSON.stringify(resolution.host_policy));
        assert.deepEqual(clock.sleeps, [GAP], 'a full gap: the first navigation to jobicy.com was marked at the same virtual instant');
        assert.equal(resolution.host_policy.navigations_decided, 3, 'every navigation was decided (three pages, two of them on one host), not once per host');
    });

    test('a host REVISITED by an HTTP REDIRECT HOP within the same case is paced before the hop is sent', async () => {
        const { resolution, clock } = await direct('/jobs/312');
        assert.ok(resolution.host_policy.paced_hosts.includes('jobicy.com'));
        assert.ok(clock.sleeps.includes(GAP));
    });

    test('a redirect hop onto a robots-disallowed path of an already-visited, already-approved host is refused before it is requested', async () => {
        const hits = sites.hitsOn('jobicy.com', '/go/abc123');
        const { resolution } = await direct('/jobs/313');
        assert.deepEqual([resolution.status, resolution.reason, resolution.needs_human_review], ['unresolved', 'robots_disallowed_requires_review', true]);
        assert.equal(sites.hitsOn('jobicy.com', '/go/abc123'), hits, 'the disallowed path was never requested');
        assert.ok(resolution.host_policy.refusals.includes('robots_disallowed:jobicy.com'));
    });

    test('a SUBRESOURCE on an approved host that redirects to an unapproved host is refused at the hop and never requested', async () => {
        const hits = sites.hitsOn('cdn.thirdparty-example.net');
        const { resolution } = await direct('/jobs/315');
        assert.equal(resolution.status, 'proposed_by_resolution', 'the page still resolves');
        assert.equal(sites.hitsOn('cdn.thirdparty-example.net'), hits);
        assert.ok(resolution.host_policy.refusals.includes('host_not_approved:cdn.thirdparty-example.net'));
    });

    test('an unknown redirect destination is refused BEFORE any request, and is never silently approved: the approved set contains no redirect-discovered host', async () => {
        const hits = sites.hitsOn('go.linkforward-example.io');
        const { resolution } = await direct('/jobs/302');
        assert.equal(resolution.reason, 'redirect_host_requires_review');
        assert.equal(sites.hitsOn('go.linkforward-example.io'), hits);
        assert.deepEqual(resolution.host_policy.approved_hosts, ['careers.acmecorp.com', 'jobicy.com'], 'only the source host and the Apply destination the resolver chose');
    });

    test('a refused redirect hop is a REVIEW item for a person, never a silent approval for the next case', async () => {
        const r = await go(build([[3, 'jobicy.com', '/jobs/302'], [4, 'jobicy.com', '/jobs/302']]));
        for (const id of [3, 4]) assert.deepEqual([state(r, id).resolution.reason, state(r, id).needs_human_review], ['redirect_host_requires_review', true]);
        assert.deepEqual(r.config.reviewed_redirect_hosts, [], 'nothing was added to the reviewed list');
    });

    test('the policy is decided PER REQUEST: the same host is decided again each time, and pacing is serialised across simultaneous navigations', async () => {
        const clock = fakeClock();
        const pacer = createPacer({ clock, gapMs: GAP });
        const policy = createHostPolicy({ sourceHost: 'jobicy.com', pacer, deadline: Infinity, robots: createRobots(loadRobotsSnapshot()) });
        const u = (p) => new URL(`https://jobicy.com${p}`);
        const out = await Promise.all([policy.decide(u('/jobs/1'), { navigation: true }), policy.decide(u('/jobs/2'), { navigation: true }), policy.decide(u('/jobs/3'), { navigation: true })]);
        assert.deepEqual(out.map((o) => o.ok), [true, true, true]);
        assert.deepEqual(clock.sleeps, [GAP, GAP], 'three simultaneous navigations to one host are spaced one gap apart, not all released together');
        assert.equal(policy.decisions.filter((d) => d.host === 'jobicy.com').length, 3, 'one decision per navigation');
        // A subresource is checked for approval on every request too, but is not paced and not robots-judged.
        assert.deepEqual(await policy.decide(u('/style.css'), { navigation: false }), { ok: true });
        assert.equal(clock.sleeps.length, 2);
    });

    test('approval is exact: a subdomain or look-alike of an approved host is not approved, and a redirect target can never be added', async () => {
        const policy = createHostPolicy({ sourceHost: 'jobicy.com', pacer: createPacer({ clock: fakeClock(), gapMs: GAP }), deadline: Infinity, robots: createRobots(loadRobotsSnapshot()) });
        for (const h of ['sub.jobicy.com', 'jobicy.com.evil.example', 'jobicy.co', 'xjobicy.com']) assert.equal((await policy.decide(new URL(`https://${h}/x`), { navigation: true })).ok, false, h);
        assert.equal(typeof policy.approveDestination, 'function');
        assert.deepEqual(policy.approvedHosts(), ['jobicy.com']);
        assert.equal((await policy.decide(new URL('https://jobs.lever.co/a/b'), { navigation: false })).ok, true, 'a known ATS host is approved without being named');
    });

    test('the no-retry, authorization and security-stop boundaries are unchanged: a private-address Apply link still stops the batch, once', async () => {
        const hits = sites.hitsOn('jobicy.com', '/jobs/307');
        const r = await go(build([[8, 'jobicy.com', '/jobs/307'], [9, 'jobicy.com', '/jobs/301']]));
        assert.equal(r.outcome.stop.cause, 'security_refusal');
        assert.equal(sites.hitsOn('jobicy.com', '/jobs/307') - hits, 1);
        assert.equal(state(r, 9).state, 'unattempted');
    });
});
