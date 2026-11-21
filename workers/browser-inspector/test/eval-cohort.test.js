// Stage 1: the PROPOSED cohort. It authorises nothing, copies no production record's content, and never mistakes an
// aggregator's listing URL for an employer's application URL.
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { composition, renderComposition, validateCohort } from '../eval/src/cohort.js';
import { stableJson } from '../eval/src/canon.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const FILE = path.join(ROOT, 'eval', 'cohort', 'stage1.proposed.json');
const load = () => JSON.parse(readFileSync(FILE, 'utf8'));
const clone = () => structuredClone(load());

describe('the committed proposed cohort', () => {
    const m = load();
    const c = composition(m);

    test('validates, and is explicitly only a proposal', () => {
        assert.deepEqual(validateCohort(m), []);
        assert.equal(m.status, 'proposed');
        assert.match(m.purpose, /authorises nothing/);
    });

    test('has about 30 new cases: about 20 from production discovery and 10 curated external, plus the two Stage 0 cases', () => {
        assert.deepEqual([c.production_cases, c.curated_external_cases, c.new_cases, c.total_cases], [20, 10, 30, 32]);
        assert.equal(m.cases.filter((x) => x.provenance === 'stage0_existing').length, 2);
    });

    test('marks external candidates curated_external, apart from production records, and leaves their liveness unverified', () => {
        for (const x of m.cases.filter((y) => y.provenance === 'curated_external')) {
            assert.equal(x.source, 'curated_external');
            assert.equal('record_ref' in x, false);
            assert.equal(x.liveness, 'unverified');
            assert.ok(x.title_note, `${x.id} says where its title came from`);
            assert.match(x.url, /^https:\/\//);
        }
        for (const x of m.cases.filter((y) => y.provenance === 'production_discovery')) assert.ok(Number.isInteger(x.record_ref.job_posting_id));
    });

    test('copies nothing identifying from production records: no URL, title, company, description or location, only a hash and a URL shape', () => {
        for (const x of m.cases.filter((y) => y.provenance === 'production_discovery')) {
            for (const k of ['url', 'title', 'company', 'description', 'location']) assert.equal(k in x, false, `${x.id}.${k}`);
            assert.equal(/https?:\/\//.test(JSON.stringify(x)), false, `${x.id} carries a URL`);
            assert.ok(x.record_ref.url_sha256 === null ? x.listing_url_kind === 'none' : /^[0-9a-f]{64}$/.test(x.record_ref.url_sha256));
        }
    });

    test('keeps an aggregator\'s listing URL distinct from a verified employer application URL: every aggregator listing is unresolved', () => {
        const aggs = m.cases.filter((x) => x.listing_url_kind === 'aggregator_listing');
        assert.ok(aggs.length >= 10);
        for (const x of aggs) assert.deepEqual([x.application_url.status, x.application_url.value], ['unresolved', null], x.id);
        assert.equal(m.cases.some((x) => ['human_verified', 'proposed_by_resolution'].includes(x.application_url?.status)), false);
        assert.equal(aggs.some((x) => x.expected_platform.v !== null), false, 'no platform is guessed for an unresolved destination');
    });

    test('includes duplicate pairs, stale and unreachable candidates and negative controls, and about ten hand-reviewed cases', () => {
        assert.equal(Object.keys(c.duplicate_pairs).length, 3);
        for (const ids of Object.values(c.duplicate_pairs)) assert.equal(ids.length, 2);
        assert.ok(c.stale_candidates.length >= 4);
        assert.ok(c.negative_controls.length >= 3);
        assert.equal(c.hand_review_cases.length, 10);
    });

    test('names every requested platform, and says which rest only on external, demo or stale candidates; no quota is forced', () => {
        assert.deepEqual(c.platforms_with_no_case, []);
        assert.deepEqual(c.platforms_with_only_unverified_or_demo_cases, ['lever', 'workday', 'workable', 'smartrecruiters']);
        const ext = JSON.parse(readFileSync(path.join(ROOT, 'eval/cohort/external-candidates.json'), 'utf8'));
        assert.deepEqual(Object.keys(ext.gaps).sort(), ['ashby', 'eightfold', 'workable', 'workday']);
    });

    test('carries the Stage 0 limitations, including that NerdWallet is not a live Ashby inspection', () => {
        const nw = m.cases.find((x) => x.id === 'carried-nerdwallet-258');
        assert.match(nw.evidence_limitations, /NOT a successful live Ashby inspection/);
        assert.ok(m.cases.find((x) => x.id === 'carried-chainguard-4722145006').evidence_limitations.length > 40);
    });

    test('excludes jobs that have an Application: personal history is not a representative sample', () => {
        assert.match(m.selection.note, /Application are excluded/);
        assert.equal(m.selection.database_snapshot.excluded_because_an_application_exists, 10);
    });

    test('the committed composition report is current', () => {
        assert.equal(stableJson({ ...c, validation_errors: [] }), readFileSync(path.join(ROOT, 'eval/cohort/composition.json'), 'utf8'));
        assert.equal(renderComposition(c, []), readFileSync(path.join(ROOT, 'eval/cohort/composition.md'), 'utf8'));
    });
});

describe('the validator refuses a cohort that overreaches', () => {
    const bad = (mutate) => {
        const m = clone();
        mutate(m);

        return validateCohort(m);
    };
    const prod = (m) => m.cases.find((x) => x.provenance === 'production_discovery' && x.listing_url_kind === 'aggregator_listing');
    const ext = (m) => m.cases.find((x) => x.provenance === 'curated_external');

    test('a cohort that claims to be authorised or approved', () => assert.match(bad((m) => (m.authorised = true)).join(), /must not carry "authorised"/));
    test('a status other than proposed', () => assert.match(bad((m) => (m.status = 'approved')).join(), /status must be "proposed"/));
    test('an aggregator URL treated as an application URL', () => assert.match(bad((m) => (prod(m).application_url = { status: 'known_direct', value: 'https://x.example/apply' })).join(), /not an employer application URL/));
    test('a verified or resolved destination before Stage 2 has run', () => assert.match(bad((m) => (prod(m).application_url.status = 'human_verified')).join(), /not allowed in a proposed cohort/));
    test('a production record\'s URL, title or company copied in', () => {
        assert.match(bad((m) => (prod(m).url = 'https://a.example/1')).join(), /must not be copied/);
        assert.match(bad((m) => (prod(m).title = 'Engineer')).join(), /title must not be copied/);
        assert.match(bad((m) => (prod(m).record_ref.url = 'https://a.example/1')).join(), /referenced by hash/);
    });
    test('a production record with a URL but no hash', () => assert.match(bad((m) => (prod(m).record_ref.url_sha256 = null)).join(), /url_sha256 is required/));
    test('an external case that is not https, or that pretends to be a production record', () => {
        assert.match(bad((m) => (ext(m).url = 'http://x.example/job')).join(), /needs an https url/);
        assert.match(bad((m) => (ext(m).record_ref = { job_posting_id: 1 })).join(), /must not reference a production record/);
        assert.match(bad((m) => (ext(m).source = 'adzuna')).join(), /source must be curated_external/);
    });
    test('a case claiming liveness was verified', () => assert.match(bad((m) => (ext(m).liveness = 'live')).join(), /liveness must be "unverified"/));
    test('duplicate ids', () => assert.match(bad((m) => m.cases.push(structuredClone(m.cases[0]))).join(), /duplicate id/));
    test('too few or too many hand-reviewed cases', () => {
        assert.match(bad((m) => m.cases.forEach((x) => x.ground_truth.plan === 'hand_review' && (x.ground_truth.plan = 'none'))).join(), /0 cases need hand-reviewed/);
        assert.match(bad((m) => m.cases.forEach((x) => (x.ground_truth.plan = x.provenance === 'stage0_existing' ? 'existing' : 'hand_review'))).join(), /cases need hand-reviewed/);
    });
});

describe('the production selection script', () => {
    const script = readFileSync(path.join(ROOT, 'eval/cohort/select-from-database.py'), 'utf8');
    const code = script.replace(/^#!.*\n"""[\s\S]*?"""\n/, '').split('\n').filter((l) => !/^\s*(#|from \w+ import|import )/.test(l)).join('\n');

    test('is read-only by construction: SQLite opened mode=ro, SELECT statements only, no profile, resume, answer or credential table', () => {
        assert.match(script, /mode=ro/);
        assert.equal(/\b(insert|update|delete|drop|alter|create|replace)\s+(into|table|from|index)\b/i.test(code.replace(/not exists \(select/g, '')), false);
        assert.equal(/executescript|\.commit\(|PRAGMA|ATTACH|VACUUM/i.test(code), false);
        const statements = [...code.matchAll(/db\.execute\(\s*(?:"""([\s\S]*?)"""|"([^"]*)")/g)].map((x) => x[1] ?? x[2]);
        assert.ok(statements.length >= 4, 'every query is a db.execute() the test can read');
        assert.ok(statements.every((q) => /^\s*select\b/i.test(q)), 'only SELECT statements');
        const tables = statements.flatMap((q) => [...q.matchAll(/\b(?:from|join)\s+([a-z_]+)/gi)].map((t) => t[1].toLowerCase()));
        assert.deepEqual([...new Set(tables)].sort(), ['applications', 'job_postings']);
        assert.equal(/career_profile|resume|application_questions|agent_runs|workflow|credential|password|token/i.test(code), false);
        assert.match(code, /not exists \(select 1 from applications/);
    });

    test('runs against a disposable database, leaves it byte-identical, and produces a manifest that validates', (t) => {
        const py = spawnSync('python3', ['--version']);
        if (py.status !== 0) return t.skip('python3 unavailable');
        const dir = mkdtempSync(path.join(tmpdir(), 'cohort-'));
        try {
            const db = path.join(dir, 'fake.sqlite');
            const build = spawnSync('python3', ['-c', `
import sqlite3, datetime
db = sqlite3.connect(${JSON.stringify(db)})
db.executescript("create table job_postings(id integer primary key, discovery_source, company, title, source_url, location, posted_at, discovered_at, description_completeness, description); create table applications(id integer primary key, job_posting_id integer);")
now = datetime.datetime(2026,11,21)
def add(i, src, co, t, url, days, desc=None, dc='complete'):
    d = (now - datetime.timedelta(days=days)).strftime('%Y-%m-%d %H:%M:%S')
    db.execute("insert into job_postings values (?,?,?,?,?,?,?,?,?,?)", (i, src, co, t, url, 'US', d, d, dc, desc or f'description {i}'))
i = 1
for k in range(6): add(i, 'eightfold', f'Co{k%2}', f'Role {k}', f'https://jobs.nvidia.com/careers/job/{1000+k}' if k%2 else f'https://x{k}.eightfold.ai/careers/job/{2000+k}', 10); i += 1
add(i, 'manual', 'Ash', 'Eng', 'https://jobs.ashbyhq.com/ash/11111111-1111-1111-1111-111111111111', 5); i += 1
add(i, 'manual', 'NoUrl', 'Eng', None, 5); i += 1
add(i, 'manual', 'Ind', 'Eng', 'https://www.indeed.com/viewjob?jk=1', 5); i += 1
for g in range(4):
    for _ in range(2): add(i, 'jobicy', f'JC{g}', f'Dup {g}', f'https://jobicy.com/jobs/{i}-dup', 10, desc=f'same {g}'); i += 1
for g in range(2):
    for _ in range(2): add(i, 'adzuna', f'AZ{g}', f'Dup {g}', f'https://www.adzuna.com/land/ad/{i}?se=x', 70, desc=f'same {g}', dc='preview'); i += 1
for k in range(8): add(i, 'adzuna', f'AS{k}', f'Single {k}', f'https://www.adzuna.com/land/ad/{i}?se=x', 5 if k < 3 else 70, dc='preview'); i += 1
for k in range(4): add(i, 'jobicy', f'JS{k}', f'Single {k}', f'https://jobicy.com/jobs/{i}-s', 9); i += 1
for k in range(4): add(i, 'himalayas', f'HS{k}', f'Single {k}', f'https://himalayas.app/companies/c{k}/jobs/j{i}', 9); i += 1
db.execute("insert into applications values (1, 1)")
db.commit()
`]);
            assert.equal(build.status, 0, build.stderr.toString());
            const hash = (f) => createHash('sha256').update(readFileSync(f)).digest('hex');
            const before = hash(db);
            const out = path.join(dir, 'out.json');
            const run = spawnSync('python3', [path.join(ROOT, 'eval/cohort/select-from-database.py'), db, '--out', out], { encoding: 'utf8' });
            assert.equal(run.status, 0, run.stderr);
            assert.equal(hash(db), before, 'the database was not modified');
            const m = JSON.parse(readFileSync(out, 'utf8'));
            assert.equal(m.cases.some((x) => x.id === 'prod-1'), false, 'a job that has an application is excluded');
            assert.equal(m.selection.database_snapshot.excluded_because_an_application_exists, 1);
            assert.deepEqual(validateCohort(m), []);
            assert.equal(/https?:\/\/jobs\.nvidia|adzuna\.com\/land|description \d/.test(readFileSync(out, 'utf8').replace(/"url_shape"[^\n]*\n/g, '')), false, 'no production content is copied');
        } finally {
            rmSync(dir, { recursive: true, force: true });
        }
    });
});
