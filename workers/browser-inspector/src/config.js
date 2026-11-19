// Environment configuration for the Phase 3 poll runtime (src/poll.js).
// Deliberately minimal — no config file, no schema library; a handful
// of env vars read once at startup, failing fast if a required one is
// missing rather than silently running misconfigured.
import { parseTargets } from './isolation-check.js';

export function loadConfig(env = process.env) {
    const careerToolkitUrl = env.CAREER_TOOLKIT_URL;
    const workerToken = env.BROWSER_WORKER_TOKEN;

    if (!careerToolkitUrl) {
        throw new Error('CAREER_TOOLKIT_URL is required (e.g. http://192.168.1.50:8000)');
    }
    if (!workerToken) {
        throw new Error('BROWSER_WORKER_TOKEN is required — must match Well Applied\'s configured value');
    }

    return {
        careerToolkitUrl: careerToolkitUrl.replace(/\/+$/, ''),
        workerToken,
        workerIdentity: env.BROWSER_WORKER_IDENTITY || 'browser-inspector',
        // Modest polling — one user, one AI box, low-volume job
        // inspection. See README.md "Worker runtime".
        pollIntervalMs: Number(env.BROWSER_WORKER_POLL_INTERVAL_MS || 5000),
        maxBackoffMs: Number(env.BROWSER_WORKER_MAX_BACKOFF_MS || 60000),
        resultRetryAttempts: Number(env.BROWSER_WORKER_RESULT_RETRY_ATTEMPTS || 3),
        resultRetryBackoffMs: Number(env.BROWSER_WORKER_RESULT_RETRY_BACKOFF_MS || 2000),
        // Set by the isolated deployment (compose.yaml): the worker then refuses to claim work unless its own checks show
        // it is inside the network isolation. See src/isolation-check.js and isolation/README.md.
        isolation: {
            required: env.BROWSER_WORKER_REQUIRE_ISOLATION === '1',
            extraTargets: parseTargets(env.BROWSER_WORKER_ISOLATION_CANARIES),
        },
    };
}
