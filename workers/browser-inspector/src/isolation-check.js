// The worker's own, SUPPLEMENTAL evidence that it is running inside the network isolation it was deployed with. The host
// enforces the isolation (nftables, installed and watched by systemd units): this cannot prove every rule exists, and a
// compromised worker could lie about it. What it does catch is the ordinary failure, a deployment started without the
// rules or with weakened hardening, and it turns that into a worker that refuses to claim work.
//
// Two kinds of evidence:
//  1. Canary connections to addresses that must be refused. Only a fast "host unreachable" counts as proof. The isolation
//     policy answers with ICMP admin-prohibited, which Linux reports as EHOSTUNREACH. "Connection refused" would mean the
//     packet reached a real machine's closed port, and a timeout means nothing answered, so both are failures. The canaries
//     are unused addresses or closed ports, so a correct deployment never talks to a real service.
//  2. The process's own privileges: no capabilities (which also rules out raw sockets), no_new_privs set, not root.
import net from 'node:net';
import fs from 'node:fs';

/** Errno codes that mean "an on-path policy refused this", as produced by the isolation policy's ICMP rejects. */
export const REJECTED = new Set(['EHOSTUNREACH', 'ENETUNREACH']);

/** Addresses nothing legitimate lives at, in each range the policy must refuse. */
export const DEFAULT_BLOCKED_TARGETS = [
    '169.254.169.254:80', // cloud metadata
    '10.255.255.254:80',
    '172.31.255.254:80',
    '192.168.255.254:80',
    '100.127.255.254:80', // carrier-grade NAT, which includes the whole tailnet
];

/** @returns {Promise<{target: string, outcome: 'rejected'|'refused'|'connected'|'timeout'|'error', code?: string}>} */
export function tcpProbe(target, timeoutMs = 3000) {
    const [host, port] = [target.slice(0, target.lastIndexOf(':')), Number(target.slice(target.lastIndexOf(':') + 1))];

    return new Promise((resolve) => {
        const socket = net.createConnection({ host, port });
        const done = (result) => {
            socket.destroy();
            resolve({ target, ...result });
        };

        socket.setTimeout(timeoutMs, () => done({ outcome: 'timeout' }));
        socket.once('connect', () => done({ outcome: 'connected' }));
        socket.once('error', (error) =>
            done(
                REJECTED.has(error.code)
                    ? { outcome: 'rejected', code: error.code }
                    : error.code === 'ECONNREFUSED'
                      ? { outcome: 'refused', code: error.code }
                      : { outcome: 'error', code: error.code },
            ),
        );
    });
}

/** Privilege facts from /proc/self/status text. */
export function processFailures(statusText) {
    const failures = [];
    const field = (name) => new RegExp(`^${name}:\\s*(\\S+)`, 'm').exec(statusText)?.[1];

    const capEff = field('CapEff');
    if (capEff === undefined || !/^0+$/.test(capEff)) {
        failures.push(`the process holds capabilities (CapEff=${capEff ?? 'unknown'}); all must be dropped`);
    }
    if (field('NoNewPrivs') !== '1') {
        failures.push('no-new-privileges is not set');
    }
    const uid = field('Uid');
    if (uid === undefined || uid === '0') {
        failures.push('the process runs as root');
    }

    return failures;
}

/**
 * @param {object} [options]
 * @param {string[]} [options.extraTargets] more "host:port" canaries (this deployment's gateways, the LAN, the Mac)
 * @param {(target: string, timeoutMs: number) => Promise<object>} [options.probe]
 * @param {() => string} [options.readStatus]
 * @returns {Promise<{ok: boolean, failures: string[], checks: object[]}>}
 */
export async function checkIsolation({
    extraTargets = [],
    timeoutMs = 3000,
    probe = tcpProbe,
    readStatus = () => fs.readFileSync('/proc/self/status', 'utf8'),
    sleep = (ms) => new Promise((r) => setTimeout(r, ms)),
    retrySpacingMs = 1100,
} = {}) {
    const targets = [...DEFAULT_BLOCKED_TARGETS, ...extraTargets];
    const checks = await Promise.all(targets.map((t) => probe(t, timeoutMs)));
    const failures = [];

    // Linux rate-limits the ICMP errors a reject sends (a burst of 6, then one a second), so with more than six canaries some
    // refusals arrive as timeouts even when the policy is perfect. If at least one canary WAS refused (the policy exists) the
    // timed-out ones are retried one at a time, paced; with no refusal at all there is nothing to explain a timeout, and
    // it is a failure straight away.
    if (checks.some((c) => c.outcome === 'rejected')) {
        for (const [i, check] of checks.entries()) {
            if (check.outcome === 'timeout') {
                await sleep(retrySpacingMs);
                checks[i] = await probe(check.target, timeoutMs);
            }
        }
    }

    for (const check of checks) {
        if (check.outcome !== 'rejected') {
            failures.push(
                `${check.target} was not refused by the network policy (${check.outcome}${check.code ? ` ${check.code}` : ''})`,
            );
        }
    }

    try {
        failures.push(...processFailures(readStatus()));
    } catch (error) {
        failures.push(`cannot read the process's own privileges (${error.code ?? error.message})`);
    }

    return { ok: failures.length === 0, failures, checks };
}

/**
 * A cached verdict for the claim loop. A pass is reused for `ttlMs`; a failure is never cached, so the next call
 * re-checks.
 */
export function createIsolationGate(check, { ttlMs = 30_000, now = Date.now } = {}) {
    let passedAt = null;

    return async () => {
        if (passedAt !== null && now() - passedAt < ttlMs) {
            return { ok: true, failures: [] };
        }

        const verdict = await check();
        passedAt = verdict.ok ? now() : null;

        return verdict;
    };
}

/** The extra canaries an operator configured: "host:port,host:port". */
export function parseTargets(value) {
    return String(value ?? '')
        .split(',')
        .map((t) => t.trim())
        .filter((t) => /^[^:\s]+:\d{1,5}$/.test(t));
}
