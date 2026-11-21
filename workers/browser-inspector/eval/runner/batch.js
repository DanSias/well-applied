// The offline resolution batch runner's core. PURE with respect to the world: it is handed a `resolve` function, a `getUrl` function, a clock and an
// abort signal, and it touches nothing else: no network, no browser, no database, no file (the CLI writes files). Everything it is allowed to do is
// decided here, before any resolver is called.
//
// It enforces: the manifest hash is checked FIRST; concurrency 1; per-host pacing; the per-case page cap; a total navigation cap; a wall-clock deadline;
// NO retries; a whole-batch stop on any security refusal, prohibited method, changed source-URL hash or infrastructure-failure threshold; tracker redirects
// OFF unless separately and explicitly approved for named cases; and a record of every case (attempted, skipped, unattempted, refused).
import { sha256 } from '../src/canon.js';
import { sanitizeResolution, shapeOfShape } from './sanitize.js';

export const RUN_SCHEMA = 'eval-resolution-run/1';
export class BatchRefused extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}

const DEFAULTS = { infrastructure_failure_threshold: 2, site_block_per_host: 2, site_block_overall: 3 };
const PROHIBITED_METHOD = /^(post|put|patch|delete)_blocked$|^non_get/i;

export function validateBatch(manifestBytes, expectSha256) {
    // 1. The hash, before anything is parsed or trusted.
    if (typeof expectSha256 !== 'string' || !/^[0-9a-f]{64}$/.test(expectSha256)) throw new BatchRefused('manifest_hash_not_given', 'a 64-hex manifest SHA-256 must be given');
    const actual = sha256(manifestBytes);
    if (actual !== expectSha256) throw new BatchRefused('manifest_hash_mismatch', `the manifest hash ${actual} is not the expected ${expectSha256}`);
    const m = JSON.parse(manifestBytes);
    if (m.schema !== 'eval-resolution-batch/1') throw new BatchRefused('manifest_schema', `unexpected schema ${m.schema}`);
    if (m.status !== 'proposed') throw new BatchRefused('manifest_status', 'the manifest must be a proposal; it is never treated as an authorisation');
    for (const k of ['authorised', 'authorized', 'approved', 'run']) if (k in m) throw new BatchRefused('manifest_claims_authorisation', `the manifest must not carry "${k}"`);
    const e = m.execution ?? {};
    if (e.concurrency !== 1) throw new BatchRefused('manifest_limits', 'concurrency must be 1');
    if (e.retries !== 0) throw new BatchRefused('manifest_limits', 'retries must be 0');
    if (!(e.per_case?.max_pages >= 1 && e.per_case.max_pages <= 3)) throw new BatchRefused('manifest_limits', 'per-case max_pages must be 1 to 3');
    for (const k of ['total_page_navigation_cap', 'wall_clock_cap_minutes']) if (!(e[k] > 0)) throw new BatchRefused('manifest_limits', `${k} must be set`);
    if (!(e.pacing?.min_ms_between_requests_to_the_same_host >= 0)) throw new BatchRefused('manifest_limits', 'pacing must be set');
    if (m.resolver?.allow_tracker_redirects !== false) throw new BatchRefused('manifest_limits', 'the manifest must not enable tracker redirects');
    if (!Array.isArray(m.cases) || m.cases.length === 0) throw new BatchRefused('manifest_schema', 'no cases');
    for (const c of m.cases) if (!c.id || !/^[0-9a-f]{64}$/.test(c.url_sha256 ?? '') || typeof c.host !== 'string') throw new BatchRefused('manifest_schema', `case ${c.id} lacks id, host or url_sha256`);

    return { manifest: m, sha256: actual };
}

/** Tracker redirects stay off unless the config names the exact batch hash AND the exact cases. A bare flag is refused. */
export function resolveConfig(config = {}, manifestSha256, manifest) {
    const c = { ...DEFAULTS, reviewed_redirect_hosts: [], allow_tracker_redirects: false, tracker_approval: null, ...config };
    let approved = new Set();
    if (c.allow_tracker_redirects) {
        const a = c.tracker_approval;
        if (!a || a.batch_sha256 !== manifestSha256 || !Array.isArray(a.case_ids) || a.case_ids.length === 0) throw new BatchRefused('tracker_not_approved', 'allow_tracker_redirects needs tracker_approval naming this batch hash and the case ids; a bare flag is refused');
        const known = new Map(manifest.cases.map((x) => [x.id, x]));
        for (const id of a.case_ids) if (!known.get(id)?.needs_tracker_permission) throw new BatchRefused('tracker_not_approved', `${id} is not a case that needs tracker permission`);
        approved = new Set(a.case_ids);
    } else if (c.tracker_approval) throw new BatchRefused('tracker_not_approved', 'tracker_approval was given but allow_tracker_redirects is off');

    return { config: c, trackerApproved: approved };
}

const hostsOf = (resolution) => {
    const hosts = new Set();
    if (resolution.source?.host) hosts.add(resolution.source.host);
    for (const h of resolution.hops ?? []) for (const x of [h.requested?.host, h.final?.host, ...(h.redirect_chain ?? []).map((c) => c.host)]) if (x) hosts.add(x);

    return hosts;
};
const isInfrastructure = (r) => r.status === 'unreachable' && (r.reason === 'navigation_timeout' || /^net::ERR_/.test(r.reason ?? '')) || (r.status === 'unresolved' && r.reason === 'resolver_error');
const isSiteBlock = (r) => (r.status === 'blocked' && r.blocked_by === 'site') || (r.status === 'unreachable' && /^http_(403|429)$/.test(r.reason ?? ''));

/** The order cases run in: ascending id, tracker cases last. */
export const executionOrder = (manifest) => [...manifest.cases].sort((a, b) => Number(a.needs_tracker_permission) - Number(b.needs_tracker_permission) || a.id.localeCompare(b.id));

const record = (c, state, reason, extra = {}) => ({ id: c.id, source: c.source ?? null, source_shape: shapeOfShape(c.url_shape) ?? c.host, state, reason, resolution: null, needs_human_review: false, review_reasons: [], warnings: [], navigations: 0, ...extra });

/**
 * @param {object} p
 * @param {Buffer|string} p.manifestBytes @param {string} p.expectSha256
 * @param {object} [p.config]
 * @param {'plan'|'dry-run'} p.mode
 * @param {(url: string, options: object) => Promise<object>} [p.resolve] absent in plan mode
 * @param {(c: object) => Promise<string>} [p.getUrl] absent in plan mode
 * @param {{now: () => number, sleep: (ms: number) => Promise<void>}} p.clock
 * @param {AbortSignal} [p.signal] @param {(report: object) => void} [p.onCheckpoint]
 */
export async function runBatch({ manifestBytes, expectSha256, config = {}, mode, resolve, getUrl, clock, signal, onCheckpoint }) {
    if (!['plan', 'dry-run'].includes(mode)) throw new BatchRefused('no_live_mode', `mode ${mode} does not exist: there is no live mode in this milestone`);
    const { manifest, sha256: manifestSha } = validateBatch(manifestBytes, expectSha256);
    const { config: cfg, trackerApproved } = resolveConfig(config, manifestSha, manifest);
    if (mode !== 'plan' && (typeof resolve !== 'function' || typeof getUrl !== 'function')) throw new BatchRefused('no_resolver', `mode ${mode} needs a resolver and a URL source`);
    const e = manifest.execution;
    const order = executionOrder(manifest);
    const startedAt = clock.now();
    const deadline = startedAt + e.wall_clock_cap_minutes * 60_000;
    const results = new Map();
    const lastRequestAt = new Map();
    const hostBlocks = new Map();
    const stoppedHosts = new Set();
    let overallBlocks = 0;
    let consecutiveInfra = 0;
    let navigations = 0;
    let stop = null;
    let inFlight = 0;

    const snapshot = () => buildReport({ manifest, manifestSha, mode, cfg, trackerApproved, order, results, stop, navigations });
    const checkpoint = () => onCheckpoint?.(snapshot());

    for (const c of order) {
        if (!stop) {
            if (signal?.aborted) stop = { cause: 'interrupted', detail: 'the run was interrupted between cases', at_case: c.id };
            else if (clock.now() >= deadline) stop = { cause: 'wall_clock_cap', detail: `${e.wall_clock_cap_minutes} minutes`, at_case: c.id };
        }
        if (stop) {
            results.set(c.id, record(c, 'unattempted', `batch_stopped:${stop.cause}`));
            continue;
        }
        if (c.needs_tracker_permission && !trackerApproved.has(c.id)) {
            results.set(c.id, record(c, 'skipped', 'tracker_redirect_permission_not_granted'));
            continue;
        }
        if (stoppedHosts.has(c.host)) {
            results.set(c.id, record(c, 'skipped', `host_stopped:${c.host}`));
            continue;
        }
        if (mode === 'plan') {
            results.set(c.id, record(c, 'planned', 'would_be_attempted'));
            continue;
        }
        if (navigations + e.per_case.max_pages > e.total_page_navigation_cap) {
            stop = { cause: 'navigation_cap', detail: `${navigations} of ${e.total_page_navigation_cap} navigations used; the next case may use ${e.per_case.max_pages}`, at_case: c.id };
            results.set(c.id, record(c, 'unattempted', 'navigation_cap_would_be_exceeded'));
            continue;
        }

        // The source URL is re-read and must still be the URL the manifest names. Any difference stops the whole batch.
        let url;
        try {
            url = await getUrl(c);
        } catch {
            stop = { cause: 'source_url_unavailable', detail: 'the source URL could not be re-read', at_case: c.id };
            results.set(c.id, record(c, 'refused', 'source_url_unavailable'));
            continue;
        }
        if (typeof url !== 'string' || sha256(url) !== c.url_sha256) {
            stop = { cause: 'source_url_hash_changed', detail: 'the stored URL no longer matches the manifest', at_case: c.id };
            results.set(c.id, record(c, 'refused', 'source_url_hash_changed'));
            continue;
        }

        // Pacing: wait out the minimum gap since this host was last requested.
        const wait = (lastRequestAt.get(c.host) ?? -Infinity) + e.pacing.min_ms_between_requests_to_the_same_host - clock.now();
        if (wait > 0) {
            if (clock.now() + wait >= deadline) {
                stop = { cause: 'wall_clock_cap', detail: 'pacing would pass the deadline', at_case: c.id };
                results.set(c.id, record(c, 'unattempted', 'batch_stopped:wall_clock_cap'));
                continue;
            }
            await clock.sleep(wait);
        }

        // One attempt. Never a retry.
        let resolution;
        let thrown = false;
        inFlight += 1;
        if (inFlight > 1) throw new Error('concurrency violation: more than one case in flight');
        try {
            resolution = await resolve(url, { allowTrackerRedirects: trackerApproved.has(c.id), reviewedRedirectHosts: cfg.reviewed_redirect_hosts, budgetMs: e.per_case.budget_ms });
        } catch {
            thrown = true;
        } finally {
            inFlight -= 1;
        }
        const finished = clock.now();
        if (thrown || !resolution || typeof resolution !== 'object') {
            consecutiveInfra += 1;
            results.set(c.id, record(c, 'attempted', 'resolver_exception', { resolution: null, needs_human_review: false }));
            lastRequestAt.set(c.host, finished);
        } else {
            for (const h of hostsOf(resolution)) lastRequestAt.set(h, finished);
            lastRequestAt.set(c.host, finished);
            const used = resolution.hops?.length ?? 0;
            navigations += used;
            const review = [];
            if (resolution.needs_human_review) review.push(resolution.review_reason ?? resolution.reason);
            if (resolution.reason === 'unknown_redirector_requires_review' || resolution.reason === 'tracker_redirect_requires_permission') review.push(resolution.reason);
            results.set(c.id, record(c, 'attempted', resolution.reason, {
                resolution: sanitizeResolution(resolution),
                needs_human_review: Boolean(resolution.needs_human_review),
                review_reasons: [...new Set(review)],
                warnings: [...(resolution.warnings ?? [])].sort(),
                navigations: used,
            }));

            // ---- stop rules: any one of these ends the whole batch (or the host) ----
            if (resolution.status === 'blocked' && resolution.blocked_by === 'policy') {
                const method = PROHIBITED_METHOD.test(resolution.refused ?? '') || (resolution.warnings ?? []).some((w) => /^blocked_non_get/.test(w));
                stop = { cause: method ? 'prohibited_method' : 'security_refusal', detail: resolution.reason, at_case: c.id };
            } else if ((resolution.warnings ?? []).some((w) => /^blocked_non_get/.test(w))) {
                stop = { cause: 'prohibited_method', detail: 'a non-GET request was attempted and blocked', at_case: c.id };
            }
            consecutiveInfra = isInfrastructure(resolution) ? consecutiveInfra + 1 : 0;
            if (isSiteBlock(resolution)) {
                overallBlocks += 1;
                hostBlocks.set(c.host, (hostBlocks.get(c.host) ?? 0) + 1);
                if (hostBlocks.get(c.host) >= cfg.site_block_per_host) stoppedHosts.add(c.host);
                if (!stop && overallBlocks >= cfg.site_block_overall) stop = { cause: 'site_blocking_overall', detail: `${overallBlocks} blocked or rate-limited cases`, at_case: c.id };
            }
        }
        if (!stop && consecutiveInfra >= cfg.infrastructure_failure_threshold) stop = { cause: 'infrastructure_failures', detail: `${consecutiveInfra} consecutive`, at_case: c.id };
        checkpoint();
    }
    const report = snapshot();
    onCheckpoint?.(report);

    return report;
}

function buildReport({ manifest, manifestSha, mode, cfg, trackerApproved, order, results, stop, navigations }) {
    const cases = order.map((c) => results.get(c.id) ?? record(c, 'unattempted', stop ? `batch_stopped:${stop.cause}` : 'not_reached'));
    const count = (f) => cases.filter(f).length;
    const statuses = {};
    for (const c of cases) if (c.resolution) statuses[c.resolution.status] = (statuses[c.resolution.status] ?? 0) + 1;

    return {
        schema: RUN_SCHEMA,
        mode,
        manifest: { schema: manifest.schema, sha256: manifestSha, source_cohort_sha256: manifest.source_cohort?.sha256 ?? null },
        authorisation: 'none: a manifest is a proposal and a dry run or plan authorises nothing',
        config: {
            concurrency: 1,
            retries: 0,
            per_case_max_pages: manifest.execution.per_case.max_pages,
            total_page_navigation_cap: manifest.execution.total_page_navigation_cap,
            wall_clock_cap_minutes: manifest.execution.wall_clock_cap_minutes,
            min_ms_between_requests_to_the_same_host: manifest.execution.pacing.min_ms_between_requests_to_the_same_host,
            infrastructure_failure_threshold: cfg.infrastructure_failure_threshold,
            site_block_per_host: cfg.site_block_per_host,
            site_block_overall: cfg.site_block_overall,
            allow_tracker_redirects: cfg.allow_tracker_redirects,
            tracker_approved_case_ids: [...trackerApproved].sort(),
            reviewed_redirect_hosts: [...cfg.reviewed_redirect_hosts].sort(),
        },
        outcome: { completed: stop === null && count((c) => c.state === 'unattempted') === 0, stop },
        totals: {
            cases: cases.length,
            attempted: count((c) => c.state === 'attempted'),
            planned: count((c) => c.state === 'planned'),
            skipped: count((c) => c.state === 'skipped'),
            unattempted: count((c) => c.state === 'unattempted'),
            refused: count((c) => c.state === 'refused'),
            navigations,
            needs_human_review: count((c) => c.needs_human_review),
        },
        by_resolution_status: Object.fromEntries(Object.entries(statuses).sort(([a], [b]) => a.localeCompare(b))),
        review_queue: cases.filter((c) => c.needs_human_review || c.review_reasons.length).map((c) => ({ case: c.id, reasons: c.review_reasons })),
        cases,
    };
}
