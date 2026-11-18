import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadAgentConfig } from '../src/agent/config.ts';
import { buildDelivery } from '../src/agent/delivery.ts';
import { LeaseKeeper } from '../src/agent/lease.ts';
import {
    InputError,
    safeResumeName,
    sha256,
    toPrepareInput,
    verifyResume,
} from '../src/agent/transform.ts';
import type { PreparerClient } from '../src/agent/client.ts';

const env = (over: Record<string, string | undefined> = {}) =>
    ({
        CAREER_TOOLKIT_URL: 'http://localhost:8000',
        PREPARER_WORKER_TOKEN: 'tok',
        ...over,
    }) as NodeJS.ProcessEnv;

describe('configuration', () => {
    it('uses the Phase 1 defaults: 20 s heartbeat, 10 minute limit, no browser retries', () => {
        const c = loadAgentConfig(env());
        assert.equal(c.heartbeatMs, 20_000);
        assert.equal(c.executionLimitMs, 600_000);
        assert.equal(c.headless, true);
        assert.equal(c.fixture, null);
        assert.ok(
            !('browserRetries' in c),
            'there is no setting that could retry the browser',
        );
    });

    it('requires the URL and the preparer token', () => {
        assert.throws(
            () => loadAgentConfig(env({ CAREER_TOOLKIT_URL: undefined })),
            /CAREER_TOOLKIT_URL/,
        );
        assert.throws(
            () => loadAgentConfig(env({ PREPARER_WORKER_TOKEN: '' })),
            /PREPARER_WORKER_TOKEN/,
        );
    });

    it('refuses to send a token and applicant data over plain http to another machine', () => {
        assert.throws(
            () =>
                loadAgentConfig(
                    env({ CAREER_TOOLKIT_URL: 'http://192.168.1.50:8000' }),
                ),
            /plain http/,
        );
        assert.doesNotThrow(() =>
            loadAgentConfig(
                env({ CAREER_TOOLKIT_URL: 'https://wa.example.test' }),
            ),
        );
        assert.doesNotThrow(() =>
            loadAgentConfig(
                env({ CAREER_TOOLKIT_URL: 'http://127.0.0.1:8000' }),
            ),
        );
        assert.doesNotThrow(() =>
            loadAgentConfig(
                env({
                    CAREER_TOOLKIT_URL: 'http://192.168.1.50:8000',
                    PREPARER_ALLOW_INSECURE_HTTP: '1',
                }),
            ),
        );
    });

    it('enables the local fixture mode only with both settings', () => {
        assert.deepEqual(
            loadAgentConfig(
                env({
                    PREPARER_ALLOW_LOCAL_FIXTURE: '1',
                    PREPARER_FIXTURE_ATS: 'greenhouse',
                }),
            ).fixture,
            { ats: 'greenhouse' },
        );
        assert.throws(
            () => loadAgentConfig(env({ PREPARER_ALLOW_LOCAL_FIXTURE: '1' })),
            /FIXTURE_ATS/,
        );
        assert.equal(
            loadAgentConfig(env({ PREPARER_FIXTURE_ATS: 'greenhouse' }))
                .fixture,
            null,
        );
        assert.throws(
            () => loadAgentConfig(env({ PREPARER_FIXTURE_ATS: 'workday' })),
            /lever or greenhouse/,
        );
    });

    it('rejects nonsense numbers', () => {
        assert.throws(
            () => loadAgentConfig(env({ PREPARER_HEARTBEAT_MS: '0' })),
            /positive integer/,
        );
        assert.throws(
            () => loadAgentConfig(env({ PREPARER_EXECUTION_LIMIT_MS: 'abc' })),
            /positive integer/,
        );
    });
});

describe('input transformation', () => {
    const claim = {
        agentRunId: 5,
        applicationUrl: 'https://job-boards.greenhouse.io/x/jobs/1',
        resumeSha256: null,
        resumeBytes: null,
    };
    const input = (over: Record<string, unknown> = {}) => ({
        schema_version: 1,
        agent_run_id: 5,
        application_url: 'https://job-boards.greenhouse.io/x/jobs/1?gh_src=abc',
        applicant: { email: 'a@example.test' },
        answers: {},
        ...over,
    });

    it('keeps the query on the application URL the engine opens, and checks origin and path against the claim', () => {
        const { prepare } = toPrepareInput(claim, input(), '/tmp/r.pdf');
        assert.equal(
            prepare.applicationUrl,
            'https://job-boards.greenhouse.io/x/jobs/1?gh_src=abc',
        );
        assert.equal(prepare.runId, 'wa-5');
        assert.equal(prepare.schemaVersion, 1);
        assert.throws(
            () =>
                toPrepareInput(
                    claim,
                    input({
                        application_url:
                            'https://job-boards.greenhouse.io/y/jobs/1',
                    }),
                    '/tmp/r.pdf',
                ),
            /input_url_mismatch/,
        );
    });

    it('drops unknown keys, blanks and non-strings, and invents nothing', () => {
        const r = toPrepareInput(
            claim,
            input({
                applicant: {
                    email: 'a@example.test',
                    first_name: '',
                    phone: 5,
                    rogue: 'x',
                    last_name: 'Example',
                },
            }),
            '/tmp/r.pdf',
        );
        assert.deepEqual(r.prepare.applicant, {
            email: 'a@example.test',
            last_name: 'Example',
        });
        assert.deepEqual(r.sent, ['email', 'last_name']);
        assert.equal(r.ignored, 3);
    });

    it('refuses another schema version, another run, or a malformed applicant', () => {
        assert.throws(
            () => toPrepareInput(claim, input({ schema_version: 2 }), 'x'),
            /input_schema_unsupported/,
        );
        assert.throws(
            () => toPrepareInput(claim, input({ agent_run_id: 6 }), 'x'),
            /input_run_mismatch/,
        );
        assert.throws(
            () => toPrepareInput(claim, input({ applicant: ['a'] }), 'x'),
            /input_applicant_invalid/,
        );
    });

    it('verifies the resume against the claim, the snapshot and the response header', () => {
        const bytes = Buffer.from('%PDF-1.7\nx');
        const sha = sha256(bytes);
        const facts = {
            ...claim,
            resumeSha256: sha,
            resumeBytes: bytes.length,
        };
        assert.doesNotThrow(() =>
            verifyResume(bytes, sha, facts, {
                sha256: sha,
                bytes: bytes.length,
            }),
        );
        assert.throws(
            () => verifyResume(bytes, 'f'.repeat(64), facts, { sha256: sha }),
            (e: unknown) =>
                e instanceof InputError && e.reason === 'resume_hash_mismatch',
        );
        assert.throws(
            () =>
                verifyResume(
                    bytes,
                    sha,
                    { ...facts, resumeSha256: 'e'.repeat(64) },
                    { sha256: sha },
                ),
            /resume_hash_mismatch/,
        );
        assert.throws(
            () => verifyResume(bytes, sha, facts, { sha256: 'd'.repeat(64) }),
            /resume_hash_mismatch/,
        );
        assert.throws(
            () =>
                verifyResume(
                    bytes,
                    sha,
                    { ...facts, resumeBytes: 3 },
                    { sha256: sha },
                ),
            /resume_size_mismatch/,
        );
        assert.throws(
            () => verifyResume(bytes, null, facts, { sha256: sha }),
            /resume_hash_mismatch/,
            'a missing pin is not a pass',
        );
        const notPdf = Buffer.from('<html>');
        assert.throws(
            () =>
                verifyResume(
                    notPdf,
                    sha256(notPdf),
                    {
                        ...claim,
                        resumeSha256: sha256(notPdf),
                        resumeBytes: null,
                    },
                    { sha256: sha256(notPdf) },
                ),
            /resume_not_pdf/,
        );
    });

    it('uses only a safe basename for the resume file', () => {
        assert.equal(
            safeResumeName('Alex-Example-Resume-Co.pdf'),
            'Alex-Example-Resume-Co.pdf',
        );
        assert.equal(safeResumeName('../../etc/passwd'), 'resume.pdf');
        assert.equal(safeResumeName('a/b/c.pdf'), 'c.pdf');
        assert.equal(safeResumeName('x y.pdf'), 'resume.pdf');
        assert.equal(safeResumeName(undefined), 'resume.pdf');
    });
});

describe('result projection', () => {
    it('is a failure, not a report, when the engine did not complete or does not know the site', async () => {
        const dir = await mkdtemp(join(tmpdir(), 'proj-'));
        const failed = await buildDelivery(1, dir, {
            outcome: 'failed',
            reasons: ['navigation failed'],
        });
        assert.equal(failed.kind, 'failure');
        assert.match(JSON.stringify(failed.payload), /navigation failed/);
        assert.equal((await buildDelivery(1, dir, null)).kind, 'failure');
        const unknownSite = await buildDelivery(1, dir, {
            outcome: 'unsupported',
            ats: null,
        });
        assert.equal(
            (unknownSite.payload as { failure_category: string })
                .failure_category,
            'unsupported_ats',
        );
    });

    it('bounds strings and lists, nulls sensitive values, and drops what the contract does not keep', async () => {
        const dir = await mkdtemp(join(tmpdir(), 'proj-'));
        const long = 'x'.repeat(5000);
        const d = await buildDelivery(1, dir, {
            schemaVersion: 2,
            ats: 'lever',
            outcome: 'blocked',
            reasons: [long, 5, 'ok'],
            fields: [
                {
                    label: long,
                    kind: 'text',
                    status: 'filled_verified',
                    sensitive: true,
                    expected: 'secret',
                    actual: 'secret',
                    control: { x: 1 },
                },
                {
                    label: 'Email',
                    kind: 'text',
                    status: 'filled_verified',
                    sensitive: false,
                    expected: 7,
                    actual: 'a@example.test',
                },
                ...Array.from({ length: 450 }, () => ({
                    label: 'f',
                    kind: 'text',
                    status: 'error',
                })),
            ],
            questions: [
                {
                    label: 'Q',
                    kind: 'select',
                    status: 'x',
                    review: 'y',
                    options: ['a'],
                    selected: ['b'],
                },
            ],
            controls: [{ text: 'Submit' }],
            finalState: { a: 1 },
            submission: {
                assertion: 'worker_did_not_submit',
                suspected: false,
                blockedAttempts: 0,
            },
        });
        const report = (d.payload as { report: Record<string, any> }).report;
        assert.equal(report.fields.length, 400);
        assert.equal(report.fields[0].label.length, 500);
        assert.equal(report.fields[0].expected, null);
        assert.equal(report.fields[0].actual, null);
        assert.equal(
            report.fields[1].expected,
            null,
            'a non-string value is not sent',
        );
        assert.equal(report.fields[1].actual, 'a@example.test');
        assert.deepEqual(
            report.reasons.map((r: string) => r.length),
            [500, 2],
        );
        assert.ok(report.warnings.some((w: string) => /truncated/.test(w)));
        const text = JSON.stringify(report);
        assert.ok(
            !text.includes('control') &&
                !text.includes('"options"') &&
                !text.includes('"selected"') &&
                !text.includes('finalState'),
        );
    });

    it('lists only screenshots that exist, with their real hashes', async () => {
        const dir = await mkdtemp(join(tmpdir(), 'proj-'));
        const png = Buffer.from([
            0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1,
        ]);
        await writeFile(join(dir, 'before-01of02-y00000.png'), png);
        const d = await buildDelivery(1, dir, {
            ats: 'lever',
            outcome: 'blocked',
            screenshots: [
                { file: 'before-01of02-y00000.png' },
                { file: 'after_fill-01of02-y00000.png' },
                { file: '../evil.png' },
                { file: 'notes.txt' },
            ],
        });
        assert.deepEqual(d.screenshots, [
            { file: 'before-01of02-y00000.png', sha256: sha256(png) },
        ]);
    });
});

describe('lease keeper', () => {
    const client = (
        reply: () => Promise<{
            status: number;
            body: { accepted: boolean } | null;
        }>,
    ): PreparerClient => ({ heartbeat: reply }) as unknown as PreparerClient;
    const lost: string[] = [];

    it('renews on a 200 and sends the current phase', async () => {
        let phases: Array<string | undefined> = [];
        const keeper = new LeaseKeeper({
            client: {
                heartbeat: async (_id: number, phase?: string) => (
                    phases.push(phase),
                    { status: 200, body: { accepted: true } }
                ),
            } as unknown as PreparerClient,
            runId: 1,
            intervalMs: 10,
            leaseSeconds: 60,
            claimedAt: Date.now(),
            onLost: (w) => lost.push(w),
            log: () => {},
        });
        keeper.start();
        keeper.setPhase('delivering');
        await new Promise((r) => setTimeout(r, 80));
        await keeper.stop();
        assert.ok(phases.length >= 3);
        assert.equal(phases.at(-1), 'delivering');
        assert.equal(keeper.lost, null);
    });

    it('loses the lease at once on a 403, 404 or 409', async () => {
        for (const status of [403, 404, 409]) {
            const why: string[] = [];
            const keeper = new LeaseKeeper({
                client: client(async () => ({ status, body: null })),
                runId: 1,
                intervalMs: 5,
                leaseSeconds: 60,
                claimedAt: Date.now(),
                onLost: (w) => why.push(w),
                log: () => {},
            });
            keeper.start();
            await new Promise((r) => setTimeout(r, 50));
            await keeper.stop();
            assert.deepEqual(why, ['rejected'], `status ${status}`);
        }
    });

    it('survives a server error, and loses the lease only when it can no longer be proven live', async () => {
        let t = 0;
        const why: string[] = [];
        const keeper = new LeaseKeeper({
            client: client(async () => ({ status: 503, body: null })),
            runId: 1,
            intervalMs: 5,
            leaseSeconds: 1,
            claimedAt: 0,
            onLost: (w) => why.push(w),
            log: () => {},
            now: () => t,
        });
        keeper.start();
        await new Promise((r) => setTimeout(r, 30));
        assert.deepEqual(why, [], 'a 5xx inside the lease is tolerated');
        t = 2000;
        await new Promise((r) => setTimeout(r, 30));
        await keeper.stop();
        assert.deepEqual(why, ['lapsed']);
    });
});
