// Pure request-classification rules used by the guard. No browser, no I/O,
// so every rule can be tested exhaustively (including near-miss URLs)
// without ever touching a real origin.

import { createHash } from 'node:crypto';

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
    /**
     * The destination (origin + pathname) must equal one the page's OWN authorisation
     * response named earlier in this run (see LearnRule). Stops the exception from
     * resting on a hostname alone.
     */
    authorizedBy?: string;
    /**
     * Inspects the request body and returns null to accept or a short reason to refuse.
     * The reason may name fields but must never contain values: it ends up in a report.
     */
    validateBody?: (body: Buffer, contentType: string | null) => string | null;
}

/**
 * How the guard learns an authorised upload destination from a response the page
 * itself received from the ATS, e.g. a presigned-upload endpoint. Only the destination
 * URL is kept; the rest of the response (signed fields) is never stored.
 */
export interface LearnRule {
    /** Key under which learned destinations are filed (UploadException.authorizedBy). */
    name: string;
    /** Exact origins whose responses may teach a destination. */
    pageOrigins: readonly string[];
    /** The response's request pathname must end with this. */
    pathSuffix: string;
    /** Extracts the destination URL from the parsed JSON, or null. Must not return anything else. */
    destination(json: unknown): string | null;
}

export interface RequestFacts {
    method: string;
    url: URL;
    resourceType: string;
    /** Raw Content-Type header or null. */
    contentType: string | null;
    bodyBytes: number | null;
    /** The raw body, when the guard could read it (validators only; never stored). */
    body: Buffer | null;
    /** Destinations (origin + pathname) learned from authorisation responses, by rule name. */
    learned: Readonly<Record<string, readonly string[]>>;
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
    if (e.authorizedBy !== undefined) {
        const destination = `${f.url.origin}${f.url.pathname}`;
        if (!(f.learned[e.authorizedBy] ?? []).includes(destination)) {
            return nearMiss(
                "destination was not named by the page's own authorisation response",
            );
        }
    }
    if (e.validateBody) {
        if (f.body === null) return nearMiss('request body could not be read');
        const why = e.validateBody(f.body, f.contentType);
        if (why !== null) return nearMiss(`body: ${why}`);
    }
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

// ---------------------------------------------------------------------------
// Multipart parsing and the Greenhouse resume-upload body check (pure).
// ---------------------------------------------------------------------------

export interface MultipartPart {
    name: string | null;
    filename: string | null;
    bytes: Buffer;
}

/** Splits a multipart/form-data body. Returns null if it is not well-formed. */
export function parseMultipart(
    body: Buffer,
    contentType: string | null,
): MultipartPart[] | null {
    const m = /boundary=(?:"([^"]+)"|([^;\s]+))/i.exec(contentType ?? '');
    const boundary = m?.[1] ?? m?.[2];
    if (!boundary) return null;
    const delimiter = Buffer.from(`--${boundary}`);
    const crlf = Buffer.from('\r\n');
    const parts: MultipartPart[] = [];

    let pos = body.indexOf(delimiter);
    if (pos !== 0) return null;
    for (;;) {
        pos += delimiter.length;
        // "--" after a delimiter closes the body.
        if (body.subarray(pos, pos + 2).toString('latin1') === '--')
            return parts.length > 0 ? parts : null;
        if (!body.subarray(pos, pos + 2).equals(crlf)) return null;
        pos += 2;
        const headerEnd = body.indexOf(Buffer.from('\r\n\r\n'), pos);
        if (headerEnd < 0) return null;
        const headers = body.subarray(pos, headerEnd).toString('latin1');
        const bodyStart = headerEnd + 4;
        const next = body.indexOf(Buffer.from(`\r\n--${boundary}`), bodyStart);
        if (next < 0) return null;
        const disposition =
            /^content-disposition:[^\r\n]*$/im.exec(headers)?.[0] ?? '';
        parts.push({
            name: /[; ]name="([^"]*)"/i.exec(disposition)?.[1] ?? null,
            filename: /[; ]filename="([^"]*)"/i.exec(disposition)?.[1] ?? null,
            bytes: body.subarray(bodyStart, next),
        });
        pos = next + 2; // at the next delimiter
    }
}

/**
 * Form-field names an S3 browser upload may carry. `file` is checked separately.
 * Anything else is refused: an unexpected field is a reason to stop, not to pass.
 */
const S3_FIELD_NAME =
    /^(utf8|authenticity_token|content-type|key|acl|policy|signature|awsaccesskeyid|success_action_status|success_action_redirect|cache-control|content-disposition|x-amz-[a-z0-9-]+)$/i;
const MAX_FIELD_VALUE_BYTES = 4096;

/**
 * Accepts only a browser upload that is structurally the resume upload: exactly one
 * file part, named "file", last, carrying the chosen resume's file NAME, plus bounded,
 * allowlisted form fields including `key`. Returns a reason (field names at most,
 * never values) or null.
 *
 * LIMITATION, stated plainly: Chromium's request instrumentation (what the guard sees)
 * omits the bytes of File parts, so the file part normally arrives EMPTY here and its
 * content cannot be checked by the guard. When the bytes ARE present they must match the
 * resume's SHA-256 exactly. Content identity is otherwise established elsewhere: the
 * worker hashes the file the browser holds in the input right after selecting it (the
 * same File object the widget uploads). See prepare.ts and the README.
 */
export function validateS3ResumePost(
    body: Buffer,
    contentType: string | null,
    resume: { sha256: string; bytes: number; filename: string },
): string | null {
    if (mediaType(contentType) !== 'multipart/form-data')
        return 'not multipart/form-data';
    const parts = parseMultipart(body, contentType);
    if (parts === null) return 'malformed multipart body';

    const files = parts.filter((p) => p.filename !== null || p.name === 'file');
    if (files.length !== 1)
        return `expected exactly one file part, found ${files.length}`;
    const file = files[0]!;
    if (file.name !== 'file') return 'the file part is not named "file"';
    if (parts[parts.length - 1] !== file) return 'the file part is not last';
    if (file.filename !== resume.filename)
        return "the file part does not carry the chosen resume's file name";
    if (file.bytes.length > 0) {
        // Bytes are visible: they must be the resume, exactly.
        if (file.bytes.length !== resume.bytes)
            return 'the file part is not the size of the chosen resume';
        if (
            createHash('sha256').update(file.bytes).digest('hex') !==
            resume.sha256
        )
            return 'the file part is not the chosen resume';
    }

    const names = new Set<string>();
    for (const p of parts) {
        if (p === file) continue;
        if (p.name === null) return 'a form part has no name';
        if (!S3_FIELD_NAME.test(p.name))
            return `unexpected form field name "${p.name.slice(0, 40)}"`;
        if (p.filename !== null) return `form field "${p.name}" carries a file`;
        if (p.bytes.length > MAX_FIELD_VALUE_BYTES)
            return `form field "${p.name}" is larger than ${MAX_FIELD_VALUE_BYTES} bytes`;
        if (names.has(p.name.toLowerCase()))
            return `form field "${p.name}" is repeated`;
        names.add(p.name.toLowerCase());
    }
    if (!names.has('key')) return 'no "key" form field';
    return null;
}
