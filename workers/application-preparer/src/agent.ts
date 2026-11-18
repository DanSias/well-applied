// Well Applied's application-preparation agent: polls Laravel's preparer protocol, runs the existing preparation engine
// once per claimed attempt, and delivers the result. See README.md "Laravel agent".
//
//   CAREER_TOOLKIT_URL=http://localhost:8000 PREPARER_WORKER_TOKEN=... node src/agent.ts [--once]
//
// It does not change what the engine does. It never submits, never retries the browser, and logs no applicant data.
import { createClient } from './agent/client.ts';
import { loadAgentConfig } from './agent/config.ts';
import { consoleLog } from './agent/log.ts';
import { processClaim, recoverPending } from './agent/run.ts';

const sleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

async function main(): Promise<void> {
    const once = process.argv.includes('--once');
    const cfg = loadAgentConfig();
    const client = createClient(cfg);
    const log = consoleLog;

    const shutdown = new AbortController();
    for (const sig of ['SIGINT', 'SIGTERM'] as const)
        process.on(sig, () => {
            log('shutdown_requested', { signal: sig });
            shutdown.abort();
        });

    log('starting', {
        identity: cfg.identity,
        heartbeat_ms: cfg.heartbeatMs,
        execution_limit_ms: cfg.executionLimitMs,
    });
    await recoverPending({ cfg, client, log }).catch(() =>
        log('recover_failed'),
    );

    let failures = 0;
    while (!shutdown.signal.aborted) {
        try {
            const r = await client.claim();
            failures = 0;
            if (r.status === 200 && r.body) {
                await processClaim(
                    { cfg, client, log, shutdown: shutdown.signal },
                    r.body,
                );
                if (once) break;
                continue;
            }
            if (r.status !== 204) {
                log('claim_unexpected', { status: r.status });
                if (r.status === 401) {
                    log('unauthorized_check_token');
                    process.exitCode = 1;
                    break;
                }
            }
            if (once) break;
            await sleep(cfg.pollIntervalMs);
        } catch {
            failures += 1;
            const backoff = Math.min(
                cfg.pollIntervalMs * 2 ** failures,
                cfg.maxBackoffMs,
            );
            log('claim_error', { backoff_ms: backoff });
            if (once) break;
            await sleep(backoff);
        }
    }
    log('stopped');
}

await main();
