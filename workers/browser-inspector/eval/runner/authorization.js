// A live run is disabled unless a SEPARATE authorization matches the exact manifest hash, the allowed case ids, the allowed source hosts and the execution
// limits. The authorization is a file a person creates when they grant a run; a manifest never grants one. Pure: no I/O, the clock is passed in.
import { sha256, stableJson } from '../src/canon.js';

export const AUTHORIZATION_SCHEMA = 'eval-live-authorization/1';
/** Hard ceilings for this milestone (the proposed pilot). Raising one is a code change that must be reviewed, not a field in a file. */
export const LIVE_CEILINGS = Object.freeze({ max_cases: 3, max_pages_per_case: 3, total_page_navigations: 9, wall_clock_minutes: 8, min_pacing_ms: 10_000, max_validity_hours: 24 });

export class AuthorizationRefused extends Error {
    constructor(code, message) {
        super(message);
        this.code = code;
    }
}
const refuse = (code, message) => {
    throw new AuthorizationRefused(code, message);
};
const sameSet = (a, b) => a.length === b.length && [...a].sort().join('\n') === [...b].sort().join('\n');

/** @returns {{authorization: object, sha256: string, reviewedRedirectHosts: string[]}} or throws AuthorizationRefused */
export function validateAuthorization(auth, { manifest, manifestSha, now }) {
    if (!auth || typeof auth !== 'object') refuse('authorization_missing', 'live mode needs an authorization');
    if (auth.schema !== AUTHORIZATION_SCHEMA) refuse('authorization_schema', `unexpected schema ${auth.schema}`);
    if (auth.granted !== true) refuse('authorization_not_granted', 'the authorization is not granted (a template or draft is never one)');
    if (typeof auth.granted_by !== 'string' || auth.granted_by.trim() === '') refuse('authorization_not_granted', 'granted_by is required');
    if (typeof auth.statement !== 'string' || auth.statement.trim().length < 40) refuse('authorization_not_granted', 'the statement the person gave is required, verbatim');
    if (auth.manifest_sha256 !== manifestSha) refuse('authorization_manifest_mismatch', 'the authorization names a different manifest hash');

    const ids = manifest.cases.map((c) => c.id);
    if (!Array.isArray(auth.case_ids) || !sameSet(auth.case_ids, ids)) refuse('authorization_cases_mismatch', 'the allowed case ids are not exactly the manifest\'s cases');
    if (ids.length > LIVE_CEILINGS.max_cases) refuse('authorization_ceiling', `a live run is limited to ${LIVE_CEILINGS.max_cases} cases in this milestone`);
    if (manifest.cases.some((c) => c.needs_tracker_permission)) refuse('authorization_tracker', 'a case that needs tracker permission cannot be run live in this milestone');

    const hosts = (auth.source_hosts ?? []).map((h) => String(h).toLowerCase());
    for (const c of manifest.cases) if (!hosts.includes(c.host.toLowerCase())) refuse('authorization_hosts_mismatch', `${c.host} is not an allowed source host`);
    if (hosts.some((h) => !manifest.cases.some((c) => c.host.toLowerCase() === h))) refuse('authorization_hosts_mismatch', 'the authorization allows a source host no case uses');

    const e = manifest.execution;
    const l = auth.limits ?? {};
    const want = { concurrency: 1, retries: 0, max_pages_per_case: e.per_case.max_pages, total_page_navigations: e.total_page_navigation_cap, pacing_ms: e.pacing.min_ms_between_requests_to_the_same_host, wall_clock_minutes: e.wall_clock_cap_minutes };
    for (const [k, v] of Object.entries(want)) if (l[k] !== v) refuse('authorization_limits_mismatch', `limits.${k} is ${l[k]}, the manifest says ${v}`);
    if (l.tracker_redirects !== false) refuse('authorization_tracker', 'tracker redirects must be explicitly false');
    if (want.max_pages_per_case > LIVE_CEILINGS.max_pages_per_case || want.total_page_navigations > LIVE_CEILINGS.total_page_navigations || want.wall_clock_minutes > LIVE_CEILINGS.wall_clock_minutes || want.pacing_ms < LIVE_CEILINGS.min_pacing_ms) refuse('authorization_ceiling', 'the limits exceed this milestone\'s ceilings');

    const from = Date.parse(auth.granted_at);
    const until = Date.parse(auth.valid_until);
    if (!Number.isFinite(from) || !Number.isFinite(until)) refuse('authorization_validity', 'granted_at and valid_until must be ISO timestamps');
    if (now < from) refuse('authorization_validity', 'the authorization is not yet valid');
    if (now >= until) refuse('authorization_expired', 'the authorization has expired');
    if (until - from > LIVE_CEILINGS.max_validity_hours * 3_600_000) refuse('authorization_validity', `an authorization may be valid for at most ${LIVE_CEILINGS.max_validity_hours} hours`);

    const reviewed = (auth.reviewed_redirect_hosts ?? []).map((h) => String(h).toLowerCase());

    return { authorization: auth, sha256: sha256(stableJson(auth)), reviewedRedirectHosts: reviewed, validUntil: until };
}
