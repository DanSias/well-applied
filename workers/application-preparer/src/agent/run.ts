// One claimed attempt, start to finish, and the recovery of attempts left on disk.
//
//   claim -> input -> resume (verified) -> engine (child process, heartbeat running) -> delivery -> cleanup
//
// Guarantees, in order of importance:
//  - The browser engine runs at most ONCE per claim. Nothing here ever restarts it, whatever happens after.
//  - If the lease is lost or rejected, the engine is killed and NOTHING more is sent: no screenshot, no result.
//  - Evidence is uploaded before the result; a repeated upload or result is harmless (Laravel answers "duplicate").
//  - Only transport failures and 5xx are retried, and only for delivery. A definitive answer ends it.
//  - Applicant values and the resume are deleted the moment the engine has finished. The report and screenshots are kept
//    only until Laravel confirms them (or says it will not take them), and are never logged.
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import type { AgentConfig } from './config.ts';
import type { ClaimResponse, PreparerClient } from './client.ts';
import {
    buildDelivery,
    deliver,
    failureDelivery,
    type Delivery,
    type DeliveryEnd,
} from './delivery.ts';
import { runEngine, type EngineOptions } from './engine.ts';
import { LeaseKeeper } from './lease.ts';
import type { Log } from './log.ts';
import {
    InputError,
    safeResumeName,
    toPrepareInput,
    verifyResume,
} from './transform.ts';
import { listWorkspaces, Workspace } from './workspace.ts';

/** How an attempt ended, from the agent's side. None of these means anything was submitted. */
export type RunEnd =
    | 'delivered' // Laravel confirmed a report (any outcome, including input_required and safety_failure)
    | 'delivered_failure' // Laravel confirmed that this attempt failed
    | 'lease_lost' // stopped; nothing more sent; local files removed
    | 'stale' // Laravel no longer accepts this claim; nothing was overwritten
    | 'rejected' // Laravel refused the report's shape; a failure was reported instead
    | 'delivery_pending'; // delivery unconfirmed; report and screenshots kept for a later, safe retry

export interface RunDeps {
    cfg: AgentConfig;
    client: PreparerClient;
    log: Log;
    /** Aborts the current attempt (process shutdown). */
    shutdown?: AbortSignal;
    now?: () => number;
    sleep?: (ms: number) => Promise<void>;
    /** Tests only. */
    engineSpec?: EngineOptions['spec'];
    killGraceMs?: number;
    onEngineSpawn?: (pid: number) => void;
}

export async function processClaim(
    deps: RunDeps,
    claim: ClaimResponse,
): Promise<RunEnd> {
    const { cfg, client, log } = deps;
    const id = claim.agent_run_id;
    const now = deps.now ?? Date.now;
    const claimedAt = now();
    const ws = new Workspace(cfg.workDir, id);
    log('claimed', { run: id });

    const stop = new AbortController();
    deps.shutdown?.addEventListener('abort', () => stop.abort(), {
        once: true,
    });
    const keeper = new LeaseKeeper({
        client,
        runId: id,
        intervalMs: cfg.heartbeatMs,
        leaseSeconds: claim.lease_seconds,
        claimedAt,
        onLost: () => stop.abort(),
        log,
        now,
    });
    keeper.start();

    const finish = async (end: RunEnd, remove: boolean): Promise<RunEnd> => {
        await keeper.stop();
        if (remove) await ws.remove();
        log('finished', { run: id, end });
        return end;
    };
    const lost = () => keeper.lost !== null;

    try {
        await ws.create();

        // ---- inputs: exactly what Laravel froze, verified ----------------------------------------------------------
        const inputReply = await client.input(id);
        if (lost()) return await finish('lease_lost', true);
        if (inputReply.status !== 200 || inputReply.body === null)
            return await failAndDeliver(
                deps,
                ws,
                keeper,
                id,
                'unexpected_error',
                `could not read the frozen input (HTTP ${inputReply.status})`,
                finish,
            );

        const resumeReply = await client.resume(id);
        if (lost()) return await finish('lease_lost', true);
        if (resumeReply.status !== 200 || resumeReply.bytes === null)
            return await failAndDeliver(
                deps,
                ws,
                keeper,
                id,
                'unexpected_error',
                `could not download the resume (HTTP ${resumeReply.status})`,
                finish,
            );

        const facts = {
            agentRunId: id,
            applicationUrl: claim.application_url,
            resumeSha256: claim.resume.sha256,
            resumeBytes: claim.resume.bytes,
        };
        let prepared;
        try {
            const inputResume = (inputReply.body.resume ?? {}) as {
                sha256?: unknown;
                bytes?: unknown;
                filename?: unknown;
            };
            verifyResume(
                resumeReply.bytes,
                resumeReply.sha256Header,
                facts,
                inputResume,
            );
            const resumePath = join(
                ws.resumeDir,
                safeResumeName(inputResume.filename),
            );
            await ws.writePrivate(resumePath, resumeReply.bytes);
            prepared = toPrepareInput(facts, inputReply.body, resumePath);
            await ws.writePrivate(
                ws.inputPath,
                JSON.stringify(prepared.prepare),
            );
        } catch (e) {
            const reason =
                e instanceof InputError ? e.reason : 'input_unusable';
            return await failAndDeliver(
                deps,
                ws,
                keeper,
                id,
                'unexpected_error',
                `the inputs could not be verified (${reason})`,
                finish,
            );
        }
        log('inputs_ready', {
            run: id,
            applicant_keys: prepared.sent.length,
            ignored_keys: prepared.ignored,
        });

        // ---- the browser: once, under the lease and the execution limit --------------------------------------------
        keeper.setPhase('filling');
        const end = await runEngine({
            inputPath: ws.inputPath,
            runsDir: ws.runsDir,
            headless: cfg.headless,
            fixture: cfg.fixture,
            limitMs: cfg.executionLimitMs,
            signal: stop.signal,
            spec: deps.engineSpec,
            killGraceMs: deps.killGraceMs,
            onSpawn: deps.onEngineSpawn,
        });
        // The values and the resume are not needed again, whatever happened.
        await ws.removeSensitiveInputs();

        if (lost()) return await finish('lease_lost', true);
        if (end.kind === 'aborted') {
            // Shutting down: tell Laravel, once, if the lease is still ours. No browser rerun.
            return await failAndDeliver(
                deps,
                ws,
                keeper,
                id,
                'unexpected_error',
                'the worker was shut down during preparation',
                finish,
                1,
            );
        }
        if (end.kind === 'timeout')
            return await failAndDeliver(
                deps,
                ws,
                keeper,
                id,
                'navigation_timeout',
                'the preparation exceeded the execution limit and was stopped',
                finish,
            );

        // ---- the report: whatever the engine wrote, whatever its exit code -----------------------------------------
        const report = await readFile(
            join(ws.engineRunDir, 'report.json'),
            'utf8',
        ).then(
            (t) => JSON.parse(t) as unknown,
            () => null,
        );
        const delivery = await buildDelivery(id, ws.engineRunDir, report);
        // Kept, protected, until Laravel confirms it.
        await ws.writePrivate(ws.deliveryPath, JSON.stringify(delivery));

        keeper.setPhase('delivering');
        return await deliverAndSettle(deps, ws, keeper, delivery, finish);
    } catch (e) {
        log('run_error', {
            run: id,
            kind: e instanceof Error ? e.name : 'unknown',
        });
        if (lost()) return await finish('lease_lost', true);
        // An unexpected fault is ambiguous: say so once, never rerun.
        return await failAndDeliver(
            deps,
            ws,
            keeper,
            id,
            'unexpected_error',
            'the worker hit an unexpected error during preparation',
            finish,
            2,
        );
    }
}

type Finish = (end: RunEnd, remove: boolean) => Promise<RunEnd>;

/** A failed execution: one delivery of a failure result (idempotent), then cleanup. Never a browser rerun. */
async function failAndDeliver(
    deps: RunDeps,
    ws: Workspace,
    keeper: LeaseKeeper,
    id: number,
    category: 'navigation_timeout' | 'unsupported_ats' | 'unexpected_error',
    message: string,
    finish: Finish,
    attempts?: number,
): Promise<RunEnd> {
    await ws.removeSensitiveInputs().catch(() => {});
    if (keeper.lost !== null) return finish('lease_lost', true);
    const d = failureDelivery(id, category, message);
    await ws.writePrivate(ws.deliveryPath, JSON.stringify(d)).catch(() => {});
    return deliverAndSettle(deps, ws, keeper, d, finish, attempts);
}

async function deliverAndSettle(
    deps: RunDeps,
    ws: Workspace,
    keeper: LeaseKeeper,
    d: Delivery,
    finish: Finish,
    attempts?: number,
): Promise<RunEnd> {
    const { cfg, client, log } = deps;
    let end: DeliveryEnd = await deliver(d, {
        client,
        runDir: ws.engineRunDir,
        attempts: attempts ?? cfg.deliveryAttempts,
        backoffMs: cfg.deliveryBackoffMs,
        log,
        sleep: deps.sleep,
        isCancelled: () => keeper.lost !== null,
    });
    if (keeper.lost !== null && end.kind === 'unconfirmed')
        return finish('lease_lost', true);

    if (end.kind === 'rejected' && d.kind === 'report') {
        // The report's shape was refused. Say so with a definitive failure instead of leaving a lapse to say it.
        log('result_rejected', { run: d.runId });
        const f = failureDelivery(
            d.runId,
            'unexpected_error',
            'the result report was rejected by validation',
        );
        const second = await deliver(f, {
            client,
            runDir: ws.engineRunDir,
            attempts: cfg.deliveryAttempts,
            backoffMs: cfg.deliveryBackoffMs,
            log,
            sleep: deps.sleep,
        });
        return finish(
            second.kind === 'confirmed'
                ? 'rejected'
                : second.kind === 'stale'
                  ? 'stale'
                  : 'delivery_pending',
            second.kind !== 'unconfirmed',
        );
    }

    switch (end.kind) {
        case 'confirmed':
            return finish(
                d.kind === 'report' ? 'delivered' : 'delivered_failure',
                true,
            );
        case 'stale':
            return finish('stale', true);
        case 'rejected':
            return finish('rejected', true);
        case 'unconfirmed':
            // Keep the report and screenshots; the browser is NOT rerun.
            return finish('delivery_pending', false);
    }
}

/**
 * Attempts a crash or a failed delivery left on disk. A directory with a delivery is retried (safe: uploads and results
 * are idempotent, and a lapsed lease just answers "stale"); one without a delivery means the engine never finished, so it
 * is deleted and never rerun. Anything older than the retention limit is deleted.
 */
export async function recoverPending(
    deps: Pick<RunDeps, 'cfg' | 'client' | 'log' | 'sleep' | 'now'>,
): Promise<void> {
    const { cfg, client, log } = deps;
    for (const entry of await listWorkspaces(
        cfg.workDir,
        (deps.now ?? Date.now)(),
    )) {
        const ws = entry.workspace;
        if (!entry.hasDelivery || entry.ageMs > cfg.pendingMaxAgeMs) {
            await ws.remove();
            log('stale_workspace_removed', {
                run: entry.runId,
                had_delivery: entry.hasDelivery,
            });
            continue;
        }
        let d: Delivery;
        try {
            d = JSON.parse(await readFile(ws.deliveryPath, 'utf8')) as Delivery;
        } catch {
            await ws.remove();
            continue;
        }
        const end = await deliver(d, {
            client,
            runDir: ws.engineRunDir,
            attempts: cfg.deliveryAttempts,
            backoffMs: cfg.deliveryBackoffMs,
            log,
            sleep: deps.sleep,
        });
        log('pending_delivery', { run: entry.runId, end: end.kind });
        if (end.kind !== 'unconfirmed') await ws.remove();
    }
}
