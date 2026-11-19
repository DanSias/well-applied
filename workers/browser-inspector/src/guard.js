// Network safety for one inspection's browser context. Every request the page makes passes through here — the main
// navigation, each redirect hop, subresources and frames alike — modelled on the application-preparer's
// request guard (workers/application-preparer/src/guard.ts), without its upload exceptions (the inspector never uploads).
//
//  - http(s) only, no credentials in the URL, none of the hosts url-safety.js refuses (loopback, private ranges);
//  - the host must not RESOLVE to a private address (per-host cached DNS check);
//  - GET and HEAD only. Every other method is aborted, subresources included: an inspection reads, it never writes.
//    There are no exceptions today; if a legitimate site ever needs one it belongs in ALLOWED_NON_GET below with a
//    justification and a test, never as a blanket allowance;
//  - every redirect hop of a document (the page itself or an iframe) is judged BEFORE Chromium follows it, and a chain
//    longer than MAX_REDIRECTS is cut off.
//
// Why redirects need their own mechanism: a Playwright route handler is called once, for the first request of a chain;
// Chromium follows the redirects itself without routing them again (verified empirically for this worker, and it is why
// the application-preparer's guard does not see them either). The redirect hops are therefore paused through Chromium's
// own interception (CDP Fetch domain, at the response stage, documents only), which keeps the browser's real network
// stack: the redirect response is held, its Location is judged, and only then is it followed or failed.
//
// What it does NOT cover: redirects of subresources (images, scripts, XHR) and requests made from out-of-process
// iframes' own sessions are not paused; they are GET-only, and the egress rule (README.md "Navigation safety") is the backstop.
//
// What this is NOT: DNS-rebinding protection. A hostname can resolve to a public address here and a private one when
// Chromium connects. verifyConnectedAddress() checks the address Chromium actually connected to, but only after the page
// has loaded and run its own script, so it DETECTS a rebound connection and cannot stop that script reading it. Closing
// the gap needs an egress rule on the host or container the worker runs in. See README.md "Navigation safety".
import {
    validateUrlStructure,
    resolvesToPrivateAddress,
    isPrivateAddress,
} from './url-safety.js';

export const MAX_REDIRECTS = 8;

/**
 * Non-GET requests that are allowed. Empty on purpose. Each entry would need {method, host, pathPattern, why} and a
 * test in test/guard.test.js showing it is still bounded.
 */
export const ALLOWED_NON_GET = Object.freeze([]);

const ALLOWED_METHODS = new Set(['GET', 'HEAD']);
// Not network requests: nothing to guard.
const LOCAL_SCHEMES = new Set(['data:', 'blob:', 'about:']);

/**
 * @param {object} [options]
 * @param {string[]} [options.allowHosts] exact "host:port" values exempt from every host check. For tests that serve
 *   fixtures from a local server ONLY: inspect() never builds this from a claim, an environment variable or anything a
 *   remote party can influence.
 * @param {(hostname: string) => Promise<{safe: boolean, reason?: string}>} [options.resolve] DNS check, injectable.
 */
export function createGuard({ allowHosts = [], resolve = resolvesToPrivateAddress } = {}) {
    const allowed = new Set(allowHosts.map((h) => h.toLowerCase()));
    /** The one page that may navigate. A popup's own navigation is refused outright (see the route handler). */
    let primaryPage = null;
    const dnsCache = new Map();
    /** @type {Array<{kind: string, main: boolean, url: string, reason: string}>} */
    let violations = [];

    function isAllowedHost(url) {
        return allowed.has(url.host.toLowerCase());
    }

    async function hostIsSafe(url) {
        if (isAllowedHost(url)) return { safe: true };
        const key = url.hostname.toLowerCase();
        if (!dnsCache.has(key)) {
            dnsCache.set(key, resolve(url));
        }
        return dnsCache.get(key);
    }

    /**
     * Judges one URL for navigation (also used before following an Apply link, so a prohibited destination is refused
     * without a request ever being made).
     * @returns {Promise<{ok: true, url: URL} | {ok: false, reason: string}>}
     */
    async function checkUrl(rawUrl) {
        const structural = validateUrlStructure(rawUrl, { allowHosts: allowed });
        if (!structural.valid) return { ok: false, reason: structural.reason };

        const dns = await hostIsSafe(structural.url);
        if (!dns.safe) return { ok: false, reason: dns.reason };

        return { ok: true, url: structural.url };
    }

    function record(entry) {
        violations.push(entry);
    }

    /**
     * Pauses each document redirect before Chromium follows it. Chromium-only (the only browser this worker runs).
     *
     * @param {import('playwright').Page} page
     */
    async function attach(page) {
        primaryPage = page;
        const cdp = await page.context().newCDPSession(page);
        const { frameTree } = await cdp.send('Page.getFrameTree');
        const mainFrameId = frameTree.frame.id;
        const hops = new Map();

        cdp.on('Fetch.requestPaused', async (event) => {
            const status = event.responseStatusCode ?? 0;
            const location = (event.responseHeaders ?? []).find((h) => h.name.toLowerCase() === 'location')?.value;
            const isRedirect = status >= 300 && status < 400 && location;

            try {
                if (!isRedirect) return await cdp.send('Fetch.continueRequest', { requestId: event.requestId });

                const main = event.frameId === mainFrameId;
                const key = event.networkId ?? event.requestId;
                const depth = (hops.get(key) ?? 0) + 1;
                hops.set(key, depth);

                let target;
                try {
                    target = new URL(location, event.request.url);
                } catch {
                    record({ kind: 'prohibited', main, url: '[malformed redirect]', reason: 'malformed_url' });
                    return await cdp.send('Fetch.failRequest', { requestId: event.requestId, errorReason: 'BlockedByClient' });
                }

                if (depth > MAX_REDIRECTS) {
                    record({ kind: 'redirect_limit', main, url: redact(target), reason: 'too_many_redirects' });
                    return await cdp.send('Fetch.failRequest', { requestId: event.requestId, errorReason: 'BlockedByClient' });
                }

                const verdict = await checkUrl(target.toString());
                if (!verdict.ok) {
                    record({ kind: 'prohibited', main, url: redact(target), reason: verdict.reason });
                    return await cdp.send('Fetch.failRequest', { requestId: event.requestId, errorReason: 'BlockedByClient' });
                }

                return await cdp.send('Fetch.continueRequest', { requestId: event.requestId });
            } catch {
                // The page or request went away while it was paused: nothing left to decide.
            }
        });

        await cdp.send('Fetch.enable', {
            patterns: [{ urlPattern: '*', resourceType: 'Document', requestStage: 'Response' }],
        });
    }

    /** @param {import('playwright').BrowserContext} context */
    async function install(context) {
        await context.route('**/*', async (route) => {
            const request = route.request();
            const main = isMainFrameNavigation(request);
            let url;

            try {
                url = new URL(request.url());
            } catch {
                return route.abort('blockedbyclient');
            }

            if (LOCAL_SCHEMES.has(url.protocol)) return route.continue();

            if (primaryPage !== null && request.isNavigationRequest() && pageOf(request) !== primaryPage) {
                // window.open / target=_blank: nothing outside the one guarded page may load.
                record({ kind: 'popup', main: false, url: redact(url), reason: 'popup_blocked' });
                return route.abort('blockedbyclient');
            }

            const method = request.method().toUpperCase();
            if (!ALLOWED_METHODS.has(method) && !permittedNonGet(method, url)) {
                record({ kind: 'non_get', main, url: redact(url), reason: `${method.toLowerCase()}_blocked` });
                return route.abort('blockedbyclient');
            }

            const verdict = await checkUrl(url.toString());
            if (!verdict.ok) {
                record({ kind: 'prohibited', main, url: redact(url), reason: verdict.reason });
                return route.abort('blockedbyclient');
            }

            return route.continue();
        });
    }

    return {
        install,
        attach,
        checkUrl,
        isAllowedHost,
        /** Violations recorded since the last call; clears them. */
        take() {
            const out = violations;
            violations = [];
            return out;
        },
    };
}

function permittedNonGet(method, url) {
    return ALLOWED_NON_GET.some(
        (rule) => rule.method === method && rule.host === url.host && rule.pathPattern.test(url.pathname),
    );
}

function pageOf(request) {
    try {
        return request.frame().page();
    } catch {
        return null;
    }
}

function isMainFrameNavigation(request) {
    try {
        return request.isNavigationRequest() && request.frame().parentFrame() === null;
    } catch {
        return false;
    }
}

/** Origin and path only: a query string can carry a token. */
export function redact(url) {
    const u = typeof url === 'string' ? new URL(url) : url;
    return `${u.protocol}//${u.host}${u.pathname}`;
}

/**
 * Checks the address Chromium actually connected to for a response and every redirect hop before it. Returns the first
 * prohibited one, or null. serverAddr() is null for cached or service-worker responses: those are not a new connection.
 *
 * @param {import('playwright').Response | null} response
 * @param {ReturnType<typeof createGuard>} guard
 */
export async function verifyConnectedAddress(response, guard) {
    const finalRequest = response?.request() ?? null;
    const seen = [];
    for (let req = finalRequest; req; req = req.redirectedFrom()) seen.push(req);

    for (const req of seen) {
        const res = req === finalRequest ? response : await req.response().catch(() => null);
        const addr = await res?.serverAddr().catch(() => null);
        if (!addr) continue;
        const url = new URL(req.url());
        if (guard.isAllowedHost(url)) continue;
        if (isPrivateAddress(addr.ipAddress)) {
            return { url: redact(url), reason: 'connected_to_private_address' };
        }
    }

    return null;
}
