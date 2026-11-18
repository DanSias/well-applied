// The agent driving the REAL, unchanged engine (headless Chromium) against fixture forms served locally, with the
// fake Laravel on the other side. Nothing here touches a live site. Proves the pieces fit: Laravel-shaped input goes in,
// the engine's own report and screenshots come out and are delivered, and the engine's safeguards still decide the outcome.
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createClient } from '../src/agent/client.ts';
import type { AgentConfig } from '../src/agent/config.ts';
import { processClaim } from '../src/agent/run.ts';
import {
    startFakeLaravel,
    TOKEN,
    type FakeLaravel,
} from './support/fake-laravel.ts';
import { startServer, type TestServer } from './support/server.ts';

let forms: TestServer;
let fake: FakeLaravel;
let work: string;

before(async () => {
    forms = await startServer();
    fake = await startFakeLaravel({ leaseSeconds: 60 });
    work = await mkdtemp(join(tmpdir(), 'agent-e2e-'));
});
after(async () => {
    await fake.close();
    await forms.close();
    await rm(work, { recursive: true, force: true });
});

const config = (
    ats: 'lever' | 'greenhouse',
    over: Partial<AgentConfig> = {},
): AgentConfig => ({
    baseUrl: fake.origin,
    token: TOKEN,
    identity: 'agent-e2e',
    pollIntervalMs: 50,
    maxBackoffMs: 200,
    heartbeatMs: 500,
    executionLimitMs: 120_000,
    deliveryAttempts: 3,
    deliveryBackoffMs: 10,
    workDir: work,
    headless: true,
    pendingMaxAgeMs: 3_600_000,
    fixture: { ats },
    ...over,
});

let enginePid = 0;

async function runForm(
    path: string,
    ats: 'lever' | 'greenhouse',
    applicant?: Record<string, string>,
    over: Partial<AgentConfig> = {},
) {
    const queued = fake.enqueue({
        applicationUrl: `${forms.origin}${path}`,
        applicant,
    });
    const cfg = config(ats, over);
    const client = createClient(cfg);
    const claim = await client.claim();
    assert.equal(claim.body?.agent_run_id, queued.id);
    const logs: string[] = [];
    const end = await processClaim(
        {
            cfg,
            client,
            log: (e, f) => logs.push(JSON.stringify({ e, ...f })),
            onEngineSpawn: (pid) => (enginePid = pid),
        },
        claim.body!,
    );
    return { end, queued, logs };
}

/** True while any process of the engine's process group (node and its Chromium) is still alive. */
const groupAlive = (pid: number): boolean => {
    try {
        process.kill(-pid, 0);
        return true;
    } catch {
        return false;
    }
};

describe('the real engine through the agent', () => {
    it('prepares a Greenhouse fixture: Laravel-shaped input in, a validated report and screenshots out, nothing submitted', async () => {
        const { end, queued } = await runForm(
            '/greenhouse-form.html',
            'greenhouse',
            {
                full_name: 'Alex Example',
                first_name: 'Alex',
                last_name: 'Example',
                email: 'alex.example@example.test',
                phone: '+1 555 010 0199',
            },
        );

        assert.equal(end, 'delivered');
        assert.equal(queued.status, 'succeeded');
        const payload = queued.accepted as {
            status: string;
            report: {
                outcome: string;
                ats: string;
                fields: Array<{
                    label: string;
                    status: string;
                    actual: string | null;
                }>;
                submission: { assertion: string; suspected: boolean };
                resume: { sha256: string };
            };
            screenshots: Array<{ file: string }>;
        };
        assert.equal(payload.status, 'succeeded');
        assert.equal(payload.report.ats, 'greenhouse');
        assert.equal(
            payload.report.submission.assertion,
            'worker_did_not_submit',
        );
        assert.equal(payload.report.submission.suspected, false);
        // The resume the engine used is the one Laravel pinned.
        assert.equal(
            payload.report.resume.sha256,
            [...fake.runs.values()].at(-1) &&
                (await import('node:crypto'))
                    .createHash('sha256')
                    .update(queued.resume)
                    .digest('hex'),
        );
        // Supplied fields are filled and verified; a field Laravel did not supply (LinkedIn) is NOT invented.
        const byLabel = new Map(
            payload.report.fields.map((f) => [f.label.toLowerCase(), f]),
        );
        assert.equal(byLabel.get('email')?.status, 'filled_verified');
        const linkedin = [...byLabel.entries()].find(([k]) =>
            k.includes('linkedin'),
        );
        if (linkedin) assert.notEqual(linkedin[1].status, 'filled_verified');
        // Evidence arrived before the result, and matches the manifest.
        assert.ok(queued.screenshots.size > 0);
        assert.deepEqual(
            [...queued.screenshots.keys()].sort(),
            payload.screenshots.map((s) => s.file).sort(),
        );
        // The form server saw no submission, and nothing local is left.
        assert.deepEqual(forms.unexpected(), []);
        assert.deepEqual(await readdir(work), []);
        assert.equal(
            groupAlive(enginePid),
            false,
            'no engine or browser process was left behind',
        );
    });

    it("delivers and preserves a real safety_failure from the engine's own safeguards (never treated as ready)", async () => {
        // On a Lever page the resume attach triggers a parse request the production adapter does not authorise for a
        // local origin: the engine blocks it and reports a safety failure. The agent delivers that, as it is.
        const { end, queued } = await runForm('/lever-form.html', 'lever');

        assert.equal(end, 'delivered');
        const payload = queued.accepted as {
            status: string;
            report: { outcome: string; safetyEvents: Array<{ kind: string }> };
        };
        assert.equal(
            payload.status,
            'succeeded',
            'a delivered safety_failure is a successful execution',
        );
        assert.equal(payload.report.outcome, 'safety_failure');
        assert.ok(
            payload.report.safetyEvents.some(
                (e) => e.kind === 'blocked_mutating_request',
            ),
        );
        assert.deepEqual(
            forms.unexpected(),
            [],
            'the blocked request never reached the server',
        );
    });

    it('stops a real browser when the lease is lost, sends nothing, and leaves no browser behind', async () => {
        // Reject only once the browser is certainly up and working (the form takes many seconds to prepare).
        const timer = setTimeout(() => (fake.rejectHeartbeats = true), 3000);
        try {
            const { end, queued } = await runForm(
                '/greenhouse-form.html',
                'greenhouse',
                { email: 'alex.example@example.test' },
                { heartbeatMs: 200 },
            );
            assert.equal(end, 'lease_lost');
            assert.equal(queued.results.length, 0);
            assert.equal(queued.screenshots.size, 0);
        } finally {
            clearTimeout(timer);
            fake.rejectHeartbeats = false;
        }
        assert.deepEqual(await readdir(work), []);
        assert.equal(
            groupAlive(enginePid),
            false,
            'the engine and its browser were stopped',
        );
    });
});
