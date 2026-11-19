// The Phase 3 production runtime: poll Well Applied for claimed
// work, run the existing (unchanged) inspect() from src/inspect.js,
// and report the result back. See README.md "Worker runtime".
//
// This module's top-level code only runs the loop when executed
// directly (node src/poll.js) — importing it for tests never starts
// the loop, so runOnce()/deliverResult() can be exercised in isolation
// against a fake Well Applied HTTP server.
import { inspect } from './inspect.js';
import { createClient } from './client.js';
import { loadConfig } from './config.js';

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Delivers one inspection result, retrying transport-level failures
 * (network errors, thrown exceptions) with backoff. A response that
 * came back from Well Applied at all — including a 409 stale-result
 * rejection — is a completed delivery, not a transient failure: the
 * browser inspection is never re-run to retry a delivery.
 *
 * @param {ReturnType<typeof createClient>} client
 * @param {number} agentRunId
 * @param {object} result
 * @param {{attempts: number, backoffMs: number}} retry
 */
export async function deliverResult(client, agentRunId, result, retry, log = console.error) {
    for (let attempt = 1; attempt <= retry.attempts; attempt++) {
        try {
            const response = await client.reportResult(agentRunId, result);

            if (!response.ok) {
                log(
                    `[poll] agent_run_id=${agentRunId} result rejected (status ${response.status}): ` +
                        `${JSON.stringify(response.body)} — not retrying`,
                );
            } else {
                log(`[poll] agent_run_id=${agentRunId} result delivered (duplicate=${response.body?.duplicate ?? false})`);
            }

            return response;
        } catch (error) {
            log(`[poll] agent_run_id=${agentRunId} result delivery attempt ${attempt}/${retry.attempts} failed: ${error.message}`);

            if (attempt < retry.attempts) {
                await sleep(retry.backoffMs * attempt);
            }
        }
    }

    log(`[poll] agent_run_id=${agentRunId} giving up delivering result after ${retry.attempts} attempts — claim lease will expire`);

    return null;
}

/**
 * One claim → inspect → deliver cycle.
 *
 * @returns {Promise<boolean>} true if work was claimed (regardless of
 *   inspection/delivery outcome), false if there was none to do.
 * @param {(url: string) => Promise<object>} [inspectFn] Injectable so
 *   tests can exercise the claim/deliver wiring without launching a
 *   real browser. Production always uses the default (real inspect()).
 */
export async function runOnce(client, config, log = console.error, inspectFn = inspect) {
    const claimed = await client.claim();

    if (!claimed) {
        return false;
    }

    const startUrl = claimed.start_url ?? claimed.application_url;
    // Well Applied sends the budget (its lease minus a delivery allowance), so the lease can never expire under a run
    // that is still working. An older Well Applied sends none: fall back to the worker's own default.
    const budgetMs = Number.isFinite(claimed.budget_seconds) ? claimed.budget_seconds * 1000 : undefined;

    log(`[poll] claimed agent_run_id=${claimed.agent_run_id} origin=${claimed.start_origin ?? 'unknown'} budget_s=${claimed.budget_seconds ?? 'default'}`);

    let result;
    try {
        result = await inspectFn(startUrl, { budgetMs });
    } catch (error) {
        // inspect() reports every expected outcome itself; this is the unexpected: still deliver a failed result now
        // rather than leave the claim to expire.
        log(`[poll] agent_run_id=${claimed.agent_run_id} inspection threw: ${error?.message}`);
        result = {
            status: 'failed',
            failure_category: 'unexpected_error',
            failure_message: `The worker failed unexpectedly: ${String(error?.message ?? error).replace(/https?:\/\/[^\s"']+/gi, '[url]')}`,
            diagnostics: { timestamp: new Date().toISOString() },
        };
    }

    await deliverResult(
        client,
        claimed.agent_run_id,
        result,
        { attempts: config.resultRetryAttempts, backoffMs: config.resultRetryBackoffMs },
        log,
    );

    return true;
}

export async function runForever(client, config, log = console.error, shouldStop = () => false, inspectFn = inspect) {
    log(`[poll] starting — polling ${config.careerToolkitUrl} as "${config.workerIdentity}" every ${config.pollIntervalMs}ms`);

    let consecutiveFailures = 0;

    while (!shouldStop()) {
        try {
            const gotWork = await runOnce(client, config, log, inspectFn);
            consecutiveFailures = 0;

            if (!gotWork) {
                await sleep(config.pollIntervalMs);
            }
        } catch (error) {
            consecutiveFailures += 1;
            const backoff = Math.min(config.pollIntervalMs * 2 ** consecutiveFailures, config.maxBackoffMs);
            log(`[poll] claim/poll error: ${error.message} — backing off ${backoff}ms`);
            await sleep(backoff);
        }
    }

    log('[poll] stopping');
}

const isMain = process.argv[1] && import.meta.url === `file://${process.argv[1]}`;

if (isMain) {
    const config = loadConfig();
    const client = createClient({
        baseUrl: config.careerToolkitUrl,
        token: config.workerToken,
        workerIdentity: config.workerIdentity,
    });

    let shuttingDown = false;
    process.on('SIGINT', () => {
        shuttingDown = true;
    });
    process.on('SIGTERM', () => {
        shuttingDown = true;
    });

    await runForever(client, config, console.error, () => shuttingDown);
}
