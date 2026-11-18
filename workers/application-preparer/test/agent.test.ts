// The Laravel agent against a faithful fake of the preparer protocol and a stub engine process: claim, verified inputs,
// heartbeat, lease loss, evidence-then-result delivery, idempotent retry, and cleanup. No browser, no live site.
// (agent-engine.test.ts runs the real engine against fixture forms through the same agent.)
import { after, afterEach, before, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import {
    mkdtemp,
    readdir,
    readFile,
    rm,
    writeFile,
    mkdir,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createClient } from '../src/agent/client.ts';
import type { AgentConfig } from '../src/agent/config.ts';
import {
    processClaim,
    recoverPending,
    type RunDeps,
    type RunEnd,
} from '../src/agent/run.ts';
import type { Log } from '../src/agent/log.ts';
import {
    startFakeLaravel,
    TOKEN,
    type FakeLaravel,
    type FakeRun,
} from './support/fake-laravel.ts';
import {
    fakeEngineSpec,
    type FakeEngineScript,
} from './support/fake-engine.ts';

let fake: FakeLaravel;
let work: string;
let marker: string;
let logs: string[];
let enginePid = 0;
const alive = (pid: number): boolean => {
    try {
        process.kill(-pid, 0);
        return true;
    } catch {
        return false;
    }
};

const record: Log = (event, facts = {}) => {
    logs.push(JSON.stringify({ event, ...facts }));
};

function config(over: Partial<AgentConfig> = {}): AgentConfig {
    return {
        baseUrl: fake.origin,
        token: TOKEN,
        identity: 'agent-1',
        pollIntervalMs: 50,
        maxBackoffMs: 200,
        heartbeatMs: 100,
        executionLimitMs: 20_000,
        deliveryAttempts: 4,
        deliveryBackoffMs: 10,
        workDir: work,
        headless: true,
        pendingMaxAgeMs: 3_600_000,
        fixture: null,
        ...over,
    };
}

async function run(
    script: Partial<FakeEngineScript> = {},
    over: Partial<AgentConfig> = {},
    extra: Partial<RunDeps> = {},
) {
    const cfg = config(over);
    const client = createClient(cfg);
    const claim = await client.claim();
    assert.equal(claim.status, 200);
    const end = await processClaim(
        {
            cfg,
            client,
            log: record,
            engineSpec: fakeEngineSpec({ markerDir: marker, ...script }),
            killGraceMs: 200,
            onEngineSpawn: (pid) => (enginePid = pid),
            ...extra,
        },
        claim.body!,
    );
    return { end, claim: claim.body!, cfg, client };
}

const queue = (
    over: Parameters<FakeLaravel['enqueue']>[0] = {
        applicationUrl: 'https://job-boards.greenhouse.io/example/jobs/1',
    },
): FakeRun => fake.enqueue(over);
const starts = () =>
    existsSync(join(marker, 'starts'))
        ? readFileSync(join(marker, 'starts'), 'utf8').length
        : 0;

before(() => {});
beforeEach(async () => {
    // A generous lease so a loaded machine cannot lapse it by accident; tests about lapsing shorten it explicitly.
    fake = await startFakeLaravel({ leaseSeconds: 10 });
    work = await mkdtemp(join(tmpdir(), 'agent-work-'));
    marker = await mkdtemp(join(tmpdir(), 'agent-marker-'));
    logs = [];
    enginePid = 0;
});
afterEach(async () => {
    await fake.close();
    await rm(work, { recursive: true, force: true });
    await rm(marker, { recursive: true, force: true });
});
after(() => {});

describe('a normal attempt', () => {
    it('claims, verifies the pinned resume, runs the engine once, uploads evidence, delivers, and cleans up', async () => {
        const r = queue();
        const { end } = await run();

        assert.equal(end, 'delivered');
        assert.equal(starts(), 1, 'the engine ran exactly once');
        assert.equal(r.status, 'succeeded');
        assert.deepEqual([...r.screenshots.keys()].sort(), [
            'after_fill-01of01-y00000.png',
            'before-01of01-y00000.png',
        ]);
        assert.equal(r.results.length, 1);
        const payload = r.accepted as {
            status: string;
            report: { outcome: string; submission: Record<string, unknown> };
            screenshots: unknown[];
        };
        // A delivered input_required report is a SUCCESSFUL execution, its outcome kept apart.
        assert.equal(payload.status, 'succeeded');
        assert.equal(payload.report.outcome, 'input_required');
        assert.equal(
            payload.report.submission.assertion,
            'worker_did_not_submit',
        );
        assert.equal(payload.screenshots.length, 2);
        // Evidence before the result.
        const order = fake.log.filter((l) => /screenshots|result/.test(l));
        assert.match(order.at(-1)!, /result 200$/);
        assert.ok(order.slice(0, -1).every((l) => l.includes('screenshots')));
        // Everything local is gone after confirmed delivery.
        assert.deepEqual(await readdir(work), []);
    });

    it('turns the frozen input into the existing PrepareInput without inventing anything', async () => {
        queue({
            applicationUrl: 'https://job-boards.greenhouse.io/example/jobs/1',
            applicant: {
                full_name: 'Alex Example',
                email: 'alex.example@example.test',
                linkedin_url: '',
                rogue_key: 'x',
                first_name: 'Alex',
            },
        });
        await run();

        const seen = JSON.parse(
            await readFile(join(marker, 'input-keys.json'), 'utf8'),
        ) as {
            applicant: string[];
            runId: string;
            resumeExists: boolean;
            resumeName: string;
        };
        // Only allow-listed, non-empty string keys; nothing added (no LinkedIn, no last name); an unknown key dropped.
        assert.deepEqual(seen.applicant.sort(), [
            'email',
            'first_name',
            'full_name',
        ]);
        assert.equal(seen.runId, 'wa-1');
        assert.equal(
            seen.resumeExists,
            true,
            'the engine was handed a real resume file',
        );
        assert.equal(seen.resumeName, 'Alex-Example-Resume-Example-Co.pdf');
    });

    it('keeps the worker token and Well Applied settings out of the engine process', async () => {
        queue();
        process.env.PREPARER_WORKER_TOKEN = TOKEN;
        process.env.CAREER_TOOLKIT_URL = fake.origin;
        try {
            await run();
        } finally {
            delete process.env.PREPARER_WORKER_TOKEN;
            delete process.env.CAREER_TOOLKIT_URL;
        }
        const keys = JSON.parse(
            await readFile(join(marker, 'env.json'), 'utf8'),
        ) as string[];
        assert.ok(
            !keys.some(
                (k) => k.startsWith('PREPARER_') || k === 'CAREER_TOOLKIT_URL',
            ),
            `leaked: ${keys.filter((k) => /PREPARER|CAREER/.test(k))}`,
        );
    });

    it("sends only the contract's fields: no controls, options, selected values or final state", async () => {
        const r = queue();
        await run();

        const text = JSON.stringify(r.accepted);
        for (const leak of [
            'Submit application',
            '"controls"',
            '"options"',
            '"selected"',
            '"finalState"',
            'captchaDetails',
        ])
            assert.ok(!text.includes(leak), `${leak} was sent`);
        assert.ok(!/"submitted"|confirmation/i.test(text));
    });

    it('logs no applicant data, token, resume bytes or report content', async () => {
        queue();
        await run();

        const all = logs.join('\n');
        for (const secret of [
            'alex.example@example.test',
            'Alex',
            TOKEN,
            '%PDF',
            'Why do you want',
            'Example-Co',
            '555',
        ])
            assert.ok(!all.includes(secret), `a log line contained ${secret}`);
        assert.match(all, /claimed/);
    });
});

describe('verified inputs', () => {
    it('refuses a resume whose bytes do not match the pinned hash, and never starts the browser', async () => {
        const r = queue();
        fake.tamperResume = true;
        const { end } = await run();

        assert.equal(end, 'delivered_failure');
        assert.equal(
            starts(),
            0,
            'the engine must not run on unverified inputs',
        );
        assert.equal(r.status, 'failed');
        assert.match(JSON.stringify(r.accepted), /resume_hash_mismatch/);
        assert.deepEqual(await readdir(work), []);
    });

    it('refuses an input whose URL is not the claimed one', async () => {
        const r = queue();
        r.applicationUrl = 'https://job-boards.greenhouse.io/example/jobs/1';
        const cfg = config();
        const client = createClient(cfg);
        const claim = await client.claim();
        r.applicationUrl = 'https://job-boards.greenhouse.io/elsewhere/jobs/9'; // the input then names another form
        const end = await processClaim(
            {
                cfg,
                client,
                log: record,
                engineSpec: fakeEngineSpec({ markerDir: marker }),
            },
            claim.body!,
        );

        assert.equal(end, 'delivered_failure');
        assert.equal(starts(), 0);
        assert.match(JSON.stringify(r.accepted), /input_url_mismatch/);
    });
});

describe('heartbeats and leases', () => {
    it('renews the lease while a longer run is in progress and finishes inside the lease', async () => {
        fake.leaseSeconds = 1; // lease 1 s; the run takes 3 s: without renewal it would have lapsed
        const r = queue(); // lease 2 s; the engine runs 3 s: without renewal the lease would have lapsed
        const { end } = await run({ sleepMs: 3000 });

        assert.equal(end, 'delivered');
        assert.ok(r.heartbeats >= 10, `only ${r.heartbeats} heartbeats`);
        assert.equal(r.status, 'succeeded');
    });

    it('stops the browser, sends nothing and cleans up when Laravel rejects the lease', async () => {
        const r = queue();
        fake.rejectHeartbeats = true;
        const { end } = await run({ sleepMs: 5000 });

        assert.equal(end, 'lease_lost');
        assert.equal(alive(enginePid), false, 'the engine was stopped');
        assert.equal(r.screenshots.size, 0);
        assert.equal(r.results.length, 0);
        assert.deepEqual(
            r.afterLoss.filter((x) => /screenshots|result/.test(x)),
            [],
        );
        assert.deepEqual(await readdir(work), []);
    });

    it('treats a long network outage as a lost lease, once it can no longer prove the lease is live', async () => {
        fake.leaseSeconds = 1;
        const r = queue();
        fake.dropHeartbeats = true;
        const { end } = await run({ sleepMs: 6000 });

        assert.equal(end, 'lease_lost');
        assert.equal(r.results.length, 0);
        assert.equal(r.screenshots.size, 0);
        assert.deepEqual(await readdir(work), []);
    });

    it('tolerates a brief outage shorter than the lease', async () => {
        fake.leaseSeconds = 3;
        const r = queue();
        fake.dropHeartbeats = true;
        setTimeout(() => (fake.dropHeartbeats = false), 400);
        const { end } = await run({ sleepMs: 1500 });

        assert.equal(end, 'delivered');
        assert.equal(r.status, 'succeeded');
    });

    it('does not overwrite anything when the claim was already settled by the server', async () => {
        const r = queue();
        const cfg = config({ heartbeatMs: 10_000 }); // no heartbeat in time: the lapse is discovered at delivery
        const client = createClient(cfg);
        const claim = await client.claim();
        setTimeout(() => fake.lapse(r.id), 200);
        const end = await processClaim(
            {
                cfg,
                client,
                log: record,
                engineSpec: fakeEngineSpec({ markerDir: marker, sleepMs: 500 }),
                killGraceMs: 100,
            },
            claim.body!,
        );

        assert.equal(end, 'stale');
        assert.equal(r.status, 'failed');
        assert.equal(r.failure, 'claim_timeout', "the server's verdict stands");
        assert.equal(r.results.length, 0);
        assert.equal(starts(), 1, 'no rerun');
        assert.deepEqual(await readdir(work), []);
    });
});

describe('engine outcomes', () => {
    it('reports a timeout as a failed execution, stops the engine, and never reruns it', async () => {
        const r = queue();
        const { end } = await run(
            { sleepMs: 10_000 },
            { executionLimitMs: 400 },
        );

        assert.equal(end, 'delivered_failure');
        assert.equal(alive(enginePid), false, 'the engine was stopped');
        assert.equal(r.failure, 'navigation_timeout');
        assert.equal(starts(), 1);
    });

    it('reports a crash with no report as a failed execution and does not rerun', async () => {
        const r = queue();
        const { end } = await run({ noReport: true, exit: 1 });

        assert.equal(end, 'delivered_failure');
        assert.equal(r.failure, 'unexpected_error');
        assert.equal(starts(), 1);
    });

    it('delivers an engine "failed" outcome as a failed execution, not a report', async () => {
        const r = queue();
        const { end } = await run({
            report: { outcome: 'failed', reasons: ['navigation failed'] },
            exit: 1,
        });

        assert.equal(end, 'delivered_failure');
        assert.equal((r.accepted as { status: string }).status, 'failed');
    });

    it('keeps blocked and safety_failure reports as successful executions, and keeps the safety evidence', async () => {
        for (const outcome of ['blocked', 'safety_failure'] as const) {
            const r = queue();
            const events =
                outcome === 'safety_failure'
                    ? [
                          {
                              kind: 'blocked_mutating_request',
                              detail: 'POST /apply',
                              at: 'n',
                              phase: 'preparation',
                          },
                      ]
                    : [];
            const { end } = await run({
                report: { outcome, safetyEvents: events },
                exit: outcome === 'safety_failure' ? 4 : 3,
            });
            assert.equal(end, 'delivered');
            assert.equal(
                (
                    r.accepted as {
                        status: string;
                        report: { outcome: string; safetyEvents: unknown[] };
                    }
                ).report.outcome,
                outcome,
            );
            assert.equal(
                (r.accepted as { report: { safetyEvents: unknown[] } }).report
                    .safetyEvents.length,
                events.length,
            );
        }
    });

    it('stops the engine and reports once when the worker is shut down mid-run', async () => {
        const r = queue();
        const shutdown = new AbortController();
        setTimeout(() => shutdown.abort(), 300);
        const { end } = await run(
            { sleepMs: 10_000 },
            {},
            { shutdown: shutdown.signal },
        );

        assert.equal(end, 'delivered_failure');
        assert.equal(alive(enginePid), false, 'the engine was stopped');
        assert.match(JSON.stringify(r.accepted), /shut down/);
        assert.equal(starts(), 1);
    });
});

describe('delivery failures', () => {
    it("retries a result whose response was lost and ends with Laravel's duplicate, storing one result", async () => {
        const r = queue();
        fake.dropResultsAfter = 2; // Laravel processed the result; the answer never arrived (twice)
        const { end } = await run({}, { deliveryAttempts: 7 });

        assert.equal(end, 'delivered');
        assert.equal(r.status, 'succeeded');
        assert.ok(r.results.length >= 1 && r.results.length <= 3);
        assert.equal(
            fake.log.filter((l) => /result 200$/.test(l)).length,
            1,
            'the third attempt was answered',
        );
        assert.equal(starts(), 1, 'no browser rerun');
        assert.deepEqual(await readdir(work), []);
    });

    it('retries a dropped result request and uploads no screenshot twice with different bytes', async () => {
        const r = queue();
        fake.dropResultsBefore = 2;
        const { end } = await run({}, { deliveryAttempts: 7 });

        assert.equal(end, 'delivered');
        assert.equal(r.results.length, 1);
        assert.equal(
            fake.log.filter((l) => / 409$/.test(l)).length,
            0,
            'no conflicting upload',
        );
    });

    it('keeps the report and screenshots, protected, when delivery cannot be confirmed, and never reruns the browser', async () => {
        const r = queue();
        fake.blackholeResults = true;
        const { end } = await run();

        assert.equal(end, 'delivery_pending');
        assert.equal(starts(), 1);
        assert.equal(r.status, 'running');
        const dir = join(work, String(r.id));
        assert.equal(statSync(dir).mode & 0o777, 0o700);
        assert.equal(statSync(join(dir, 'delivery.json')).mode & 0o777, 0o600);
        assert.ok(existsSync(join(dir, 'runs', `wa-${r.id}`, 'report.json')));
        // The values and the resume are already gone; only the report and screenshots remain.
        assert.equal(existsSync(join(dir, 'input.json')), false);
        assert.equal(existsSync(join(dir, 'resume')), false);
    });

    it('delivers a kept report on a later, safe retry while the lease is live, then cleans up', async () => {
        const r = queue();
        fake.blackholeResults = true;
        await run();
        fake.blackholeResults = false;

        await recoverPending({
            cfg: config(),
            client: createClient(config()),
            log: record,
        });

        assert.equal(r.status, 'succeeded');
        assert.equal(r.results.length, 1);
        assert.deepEqual(await readdir(work), []);
        assert.equal(starts(), 1);
    });

    it('drops a kept report once Laravel says the claim is stale, without overwriting anything', async () => {
        const r = queue();
        fake.blackholeResults = true;
        await run();
        fake.blackholeResults = false;
        fake.lapse(r.id);

        await recoverPending({
            cfg: config(),
            client: createClient(config()),
            log: record,
        });

        assert.equal(r.status, 'failed');
        assert.equal(r.failure, 'claim_timeout');
        assert.equal(r.results.length, 0);
        assert.deepEqual(await readdir(work), []);
    });

    it('answers a refused report with one definitive failure instead of retrying the same body', async () => {
        const r = queue();
        fake.rejectResults = true;
        const { end } = await run();

        assert.equal(end, 'rejected');
        assert.equal((r.accepted as { status: string }).status, 'failed');
        assert.match(JSON.stringify(r.accepted), /rejected by validation/);
        assert.equal(
            fake.log.filter((l) => / 422$/.test(l)).length,
            1,
            'the refused body was sent once',
        );
    });
});

describe('recovery of what a crash left behind', () => {
    it('deletes an attempt that never finished (no delivery), never rerunning it', async () => {
        const dir = join(work, '77');
        await mkdir(join(dir, 'resume'), { recursive: true });
        await writeFile(
            join(dir, 'input.json'),
            '{"applicant":{"email":"alex.example@example.test"}}',
        );
        await writeFile(join(dir, 'resume', 'resume.pdf'), '%PDF-');

        await recoverPending({
            cfg: config(),
            client: createClient(config()),
            log: record,
        });

        assert.equal(existsSync(dir), false);
        assert.equal(starts(), 0);
    });

    it('deletes a kept delivery older than the retention limit without sending it', async () => {
        const r = queue();
        fake.blackholeResults = true;
        await run();
        fake.blackholeResults = false;

        await recoverPending({
            cfg: config({ pendingMaxAgeMs: 1 }),
            client: createClient(config()),
            log: record,
            now: () => Date.now() + 60_000,
        });

        assert.equal(r.results.length, 0);
        assert.deepEqual(await readdir(work), []);
    });

    it('ignores anything in the work directory that is not an attempt directory', async () => {
        await writeFile(join(work, 'notes.txt'), 'keep me');
        await recoverPending({
            cfg: config(),
            client: createClient(config()),
            log: record,
        });
        assert.equal(existsSync(join(work, 'notes.txt')), true);
    });
});

describe('the no-submission invariant', () => {
    it('never sends anything that claims a submission, whatever the engine report carries', async () => {
        const r = queue();
        await run({
            report: {
                submitted: true,
                applicationSubmitted: true,
                confirmation: 'Thanks for applying!',
                outcome: 'ready_for_review',
            },
            exit: 0,
        });

        const text = JSON.stringify(r.accepted);
        assert.ok(
            !/submitted|confirmation|Thanks for applying/i.test(text),
            text.slice(0, 300),
        );
        assert.equal(
            (r.accepted as { report: { submission: { assertion: string } } })
                .report.submission.assertion,
            'worker_did_not_submit',
        );
    });

    it("passes the engine's own submission statement through unchanged, so Laravel can refuse anything else", async () => {
        const r = queue();
        await run({
            report: {
                submission: {
                    assertion: 'submitted',
                    blockedAttempts: 0,
                    suspected: false,
                },
            },
        });
        // The agent must not "fix" it up: the fake (like Laravel) refuses it, and the agent then reports a failure.
        assert.equal(r.status, 'failed');
    });
});
