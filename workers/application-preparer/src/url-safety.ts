// Which URLs a run may open. Fail closed: https only, no credentials, no
// custom port, and only hosts of ATSs this worker has an adapter for.
import type { AtsName } from './contracts.ts';

const LEVER_HOSTS = new Set(['jobs.lever.co', 'jobs.eu.lever.co']);
const GREENHOUSE_HOSTS = new Set(['boards.greenhouse.io', 'job-boards.greenhouse.io', 'job-boards.eu.greenhouse.io']);

export interface UrlPolicy {
    /** Test/fixture use only: also accept http://127.0.0.1 or localhost (any port). */
    allowLocalFixture?: boolean;
}

export function atsForHost(host: string): AtsName | null {
    if (LEVER_HOSTS.has(host)) return 'lever';
    if (GREENHOUSE_HOSTS.has(host)) return 'greenhouse';
    return null;
}

/** Throws with a plain reason if the URL may not be opened. Returns the parsed URL. */
export function assertAllowedUrl(raw: string, policy: UrlPolicy = {}): URL {
    let url: URL;
    try {
        url = new URL(raw);
    } catch {
        throw new Error('applicationUrl is not a valid URL');
    }
    if (url.username || url.password) throw new Error('applicationUrl must not contain credentials');

    const local = url.hostname === '127.0.0.1' || url.hostname === 'localhost';
    if (policy.allowLocalFixture && local && url.protocol === 'http:') return url;

    if (url.protocol !== 'https:') throw new Error('applicationUrl must be https');
    if (url.port) throw new Error('applicationUrl must not specify a port');
    if (atsForHost(url.hostname) === null) throw new Error(`host ${url.hostname} is not a supported ATS host`);
    return url;
}
