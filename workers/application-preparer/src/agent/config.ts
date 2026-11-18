// Environment configuration for the Laravel-driven agent (src/agent.ts). A handful of env vars, read once, failing
// fast when a required one is missing. The defaults are the Phase 1 ones: a 20 s heartbeat against a 120 s lease and a
// 10 minute execution limit. Nothing here changes what the browser engine does.
import { isAbsolute, resolve } from 'node:path';
import type { AtsName } from '../contracts.ts';

export interface AgentConfig {
    baseUrl: string;
    token: string;
    identity: string;
    pollIntervalMs: number;
    maxBackoffMs: number;
    /** How often a running attempt renews its lease. Must be well inside the server's lease. */
    heartbeatMs: number;
    /** Hard cap on the browser stage of one attempt. */
    executionLimitMs: number;
    /** Attempts (not browser reruns) at each safe transport operation during delivery. */
    deliveryAttempts: number;
    deliveryBackoffMs: number;
    /** Where per-attempt working files live (private, 0700). */
    workDir: string;
    headless: boolean;
    /** A finished report that could not be delivered is kept this long, then deleted. */
    pendingMaxAgeMs: number;
    /** LOCAL FIXTURE TESTING ONLY: allow an http://127.0.0.1 form, treated as this ATS. */
    fixture: { ats: AtsName } | null;
}

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]']);

const int = (v: string | undefined, d: number, name: string): number => {
    if (v === undefined || v === '') return d;
    const n = Number(v);
    if (!Number.isInteger(n) || n <= 0)
        throw new Error(`${name} must be a positive integer`);
    return n;
};

export function loadAgentConfig(
    env: NodeJS.ProcessEnv = process.env,
): AgentConfig {
    const rawUrl = env.CAREER_TOOLKIT_URL;
    const token = env.PREPARER_WORKER_TOKEN;
    if (!rawUrl)
        throw new Error(
            'CAREER_TOOLKIT_URL is required (e.g. http://localhost:8000)',
        );
    if (!token)
        throw new Error(
            "PREPARER_WORKER_TOKEN is required; it must match Well Applied's configured value and differ from the inspector's",
        );

    const url = new URL(rawUrl);
    if (url.protocol !== 'https:' && url.protocol !== 'http:')
        throw new Error('CAREER_TOOLKIT_URL must be http(s)');
    // The token and applicant data travel over this connection: plain http only to this machine unless explicitly allowed.
    if (
        url.protocol === 'http:' &&
        !LOCAL_HOSTS.has(url.hostname) &&
        env.PREPARER_ALLOW_INSECURE_HTTP !== '1'
    )
        throw new Error(
            'CAREER_TOOLKIT_URL is plain http to a non-local host; use https or localhost (or set PREPARER_ALLOW_INSECURE_HTTP=1 knowingly)',
        );

    const fixtureAts = env.PREPARER_FIXTURE_ATS;
    if (
        fixtureAts !== undefined &&
        fixtureAts !== '' &&
        fixtureAts !== 'lever' &&
        fixtureAts !== 'greenhouse'
    )
        throw new Error('PREPARER_FIXTURE_ATS must be lever or greenhouse');
    const fixture =
        env.PREPARER_ALLOW_LOCAL_FIXTURE === '1' && fixtureAts
            ? { ats: fixtureAts as AtsName }
            : null;
    if (env.PREPARER_ALLOW_LOCAL_FIXTURE === '1' && !fixture)
        throw new Error(
            'PREPARER_ALLOW_LOCAL_FIXTURE=1 also needs PREPARER_FIXTURE_ATS',
        );

    const workDir = env.PREPARER_WORK_DIR ?? 'agent-work';
    return {
        baseUrl: rawUrl.replace(/\/+$/, ''),
        token,
        identity: (env.PREPARER_WORKER_IDENTITY || 'application-preparer')
            .replace(/[^A-Za-z0-9._-]/g, '-')
            .slice(0, 100),
        pollIntervalMs: int(
            env.PREPARER_POLL_INTERVAL_MS,
            5000,
            'PREPARER_POLL_INTERVAL_MS',
        ),
        maxBackoffMs: int(
            env.PREPARER_MAX_BACKOFF_MS,
            60000,
            'PREPARER_MAX_BACKOFF_MS',
        ),
        heartbeatMs: int(
            env.PREPARER_HEARTBEAT_MS,
            20000,
            'PREPARER_HEARTBEAT_MS',
        ),
        executionLimitMs: int(
            env.PREPARER_EXECUTION_LIMIT_MS,
            600000,
            'PREPARER_EXECUTION_LIMIT_MS',
        ),
        deliveryAttempts: int(
            env.PREPARER_DELIVERY_ATTEMPTS,
            5,
            'PREPARER_DELIVERY_ATTEMPTS',
        ),
        deliveryBackoffMs: int(
            env.PREPARER_DELIVERY_BACKOFF_MS,
            1000,
            'PREPARER_DELIVERY_BACKOFF_MS',
        ),
        workDir: isAbsolute(workDir) ? workDir : resolve(workDir),
        headless: env.PREPARER_HEADED !== '1',
        pendingMaxAgeMs: int(
            env.PREPARER_PENDING_MAX_AGE_MS,
            24 * 3600 * 1000,
            'PREPARER_PENDING_MAX_AGE_MS',
        ),
        fixture,
    };
}
