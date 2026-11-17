// Pure request-classification rules used by the guard. No browser, no I/O,
// so every rule can be tested exhaustively (including near-miss URLs)
// without ever touching a real origin.

/**
 * A narrowly scoped permission for ONE kind of non-GET request. Every
 * stated constraint must hold; any miss leaves the request blocked.
 * Each exception needs a name, a justification and tests.
 */
export interface UploadException {
    name: string;
    method: 'POST' | 'PUT';
    /** Exact origin including scheme, e.g. "https://jobs.lever.co". */
    origin: string;
    /** Must be anchored (^...$); enforced by the guard's constructor. */
    pathPattern: RegExp;
    why: string;
    /** Allowed Playwright resource types, e.g. ['xhr', 'fetch']. A form-POST navigation is 'document'. */
    resourceTypes?: readonly string[];
    /** Required media type prefix of the Content-Type header, e.g. "multipart/form-data". */
    contentTypePrefix?: string;
    /** Only while the worker itself is attaching the resume (see SubmissionGuard.openUploadWindow). */
    duringUploadOnly?: boolean;
    /** At most this many uses per run. */
    maxUses?: number;
    /** Reject bodies larger than this. */
    maxBodyBytes?: number;
    /** Reject any query string. Defaults to true: a legitimate endpoint here takes none. */
    forbidQuery?: boolean;
}

export interface RequestFacts {
    method: string;
    url: URL;
    resourceType: string;
    /** Raw Content-Type header or null. */
    contentType: string | null;
    bodyBytes: number | null;
    isMainFrame: boolean;
    uploadWindowOpen: boolean;
    /** How many times this exception has already been used this run. */
    priorUses: number;
}

export type MatchResult =
    | { matched: true }
    | { matched: false; nearMiss: string | null };

/** Media type without parameters (boundary, charset), lower-cased. */
export function mediaType(contentType: string | null): string | null {
    if (contentType === null) return null;
    return contentType.split(';')[0]?.trim().toLowerCase() || null;
}

/**
 * `nearMiss` is set when method, origin and path all matched but another
 * constraint failed; the guard records it so a blocked legitimate request
 * can be diagnosed without loosening anything.
 */
export function matchException(
    e: UploadException,
    f: RequestFacts,
): MatchResult {
    if (f.method.toUpperCase() !== e.method)
        return { matched: false, nearMiss: null };
    if (f.url.origin !== e.origin) return { matched: false, nearMiss: null };
    if (!e.pathPattern.test(f.url.pathname))
        return { matched: false, nearMiss: null };

    if ((e.forbidQuery ?? true) && f.url.search !== '')
        return nearMiss('query string present');
    if (f.url.hash !== '' || f.url.username !== '' || f.url.password !== '')
        return nearMiss('fragment or credentials present');
    if (!f.isMainFrame) return nearMiss('request came from a child frame');
    if (e.resourceTypes && !e.resourceTypes.includes(f.resourceType))
        return nearMiss(`resource type ${f.resourceType}`);
    if (e.contentTypePrefix) {
        const type = mediaType(f.contentType);
        if (type === null || !type.startsWith(e.contentTypePrefix))
            return nearMiss(`content-type ${type ?? 'absent'}`);
    }
    if (e.duringUploadOnly && !f.uploadWindowOpen)
        return nearMiss('not during a worker-initiated resume attach');
    if (e.maxUses !== undefined && f.priorUses >= e.maxUses)
        return nearMiss(`already used ${f.priorUses}x`);
    if (
        e.maxBodyBytes !== undefined &&
        (f.bodyBytes === null || f.bodyBytes > e.maxBodyBytes)
    )
        return nearMiss('body size unknown or too large');
    return { matched: true };
}

function nearMiss(reason: string): MatchResult {
    return { matched: false, nearMiss: reason };
}

/**
 * The one Cloudflare bot-detection beacon observed on jobs.lever.co in the
 * first supervised run:
 *   POST /cdn-cgi/challenge-platform/h/b/jsd/oneshot/<12 hex>/<token>/<16 hex>
 * It is a same-origin background POST that Cloudflare's script makes on its
 * own. It stays BLOCKED; this only decides how the event is classified.
 * Any other /cdn-cgi/ path, method, or origin is an ordinary (failing)
 * blocked mutating request.
 */
const CLOUDFLARE_JSD_ONESHOT =
    /^\/cdn-cgi\/challenge-platform\/h\/b\/jsd\/oneshot\/[0-9a-f]{12}\/[0-9.]+:[0-9]+:[A-Za-z0-9_-]+\/[0-9a-f]{16}$/;

export function isCloudflareBotDetection(
    method: string,
    url: URL,
    pageOrigin: string,
): boolean {
    return (
        method.toUpperCase() === 'POST' &&
        url.origin === pageOrigin &&
        CLOUDFLARE_JSD_ONESHOT.test(url.pathname)
    );
}
