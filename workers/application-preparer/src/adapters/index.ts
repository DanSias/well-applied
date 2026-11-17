// ATS-specific knowledge is limited to: how to recognise the ATS, and
// where its application form lives. Field discovery, classification,
// filling, verification and every safeguard are shared, so adding an ATS
// never touches the safety path.
import type { AtsName } from '../contracts.ts';
import {
    validateS3ResumePost,
    type LearnRule,
    type UploadException,
} from '../request-policy.ts';

/**
 * How to READ (never act on) an ATS's resume-parser indicator. Selectors are
 * limited to what a supervised run actually showed.
 */
export interface ResumeParserProbe {
    /** The UploadException whose use means the parse request was allowed. */
    exceptionName: string;
    success: string;
    failure: string;
    working: string;
}

export interface Adapter {
    name: AtsName;
    /** A deterministic GET-only rewrite to the page that holds the form. */
    formUrl(url: URL): URL;
    /**
     * Non-GET requests the form legitimately makes while attaching a resume.
     * Empty until a supervised run observes a real one (see README); every
     * entry needs a justification and a test.
     */
    uploadExceptions(origin: string, resume: ResumeContext): UploadException[];
    /**
     * Responses from the ATS itself that name an authorised upload destination, so an
     * exception can require that the destination came from the page's own authorisation
     * and not just match a hostname.
     */
    authorizationRules?(origin: string): LearnRule[];
    /** Absent when no indicator has been observed for this ATS. */
    resumeParser?: ResumeParserProbe;
    /** The exception whose use means the browser sent the resume to its upload destination. */
    resumeUpload?: { exceptionName: string };
}

/** The resume the worker chose, known before the browser opens. */
export interface ResumeContext {
    sha256: string;
    bytes: number;
    /** The file name the browser will send (the basename of the resume path). */
    filename: string;
}

/** The only origin the Lever exception is ever granted for. EU and any other origin are NOT included: not observed. */
export const LEVER_ORIGIN = 'https://jobs.lever.co';

/**
 * Lever's resume parse, observed in the first supervised run (the page
 * POSTs the chosen file here to pre-fill name/email, and shows "Couldn't
 * auto-read resume" if it is refused). Every constraint below must hold;
 * the content type and resource type are the expected shape of a file
 * upload, NOT yet observed (the first run did not record them), so a
 * mismatch blocks the request and records exactly what was seen.
 *
 * This is a factory so tests can build the identical rule for a local
 * origin; production grants it only for LEVER_ORIGIN (see uploadExceptions).
 */
export function leverResumeParseException(origin: string): UploadException {
    return {
        name: 'lever-resume-parse',
        method: 'POST',
        origin,
        pathPattern: /^\/parseResume$/,
        why: 'Lever posts the chosen resume file to /parseResume to pre-fill the form; observed in the first supervised run.',
        resourceTypes: ['xhr', 'fetch'],
        contentTypePrefix: 'multipart/form-data',
        duringUploadOnly: true,
        maxUses: 1,
        maxBodyBytes: 12 * 1024 * 1024,
        forbidQuery: true,
    };
}

export const lever: Adapter = {
    name: 'lever',
    formUrl(url) {
        // jobs.lever.co/{company}/{uuid} shows the posting; the form is at .../apply.
        // Only that exact shape is rewritten; anything else is opened as given.
        if (
            !/^\/[^/]+\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i.test(
                url.pathname,
            )
        )
            return url;
        const next = new URL(url.toString());
        next.pathname = `${url.pathname.replace(/\/$/, '')}/apply`;
        next.search = '';
        next.hash = '';
        return next;
    },
    uploadExceptions: (origin) =>
        origin === LEVER_ORIGIN
            ? [leverResumeParseException(LEVER_ORIGIN)]
            : [],
    // Observed on a live Lever form (second supervised run): three spans whose display toggles.
    resumeParser: {
        exceptionName: 'lever-resume-parse',
        success: '.resume-upload-success',
        failure: '.resume-upload-failure',
        working: '.resume-upload-working',
    },
};

// ---------------------------------------------------------------------------
// Greenhouse resume upload.
//
// Evidence (Greenhouse's public job-board client code, read as text, and one
// supervised run): the page first GETs `<page>/uncacheable_attributes/
// presigned_fields?fields[]=resume...`; the JSON answer names the S3 `url` and
// the signed form fields. The upload is then an XHR POST of multipart FormData
// to that url: `utf8`, the signed fields, `key`, `authenticity_token`,
// `Content-Type`, and finally `file` (the raw File). The supervised run saw
// exactly one such request:
//   POST https://grnhse-prod-jben-us-east-1.s3.amazonaws.com/  [xhr, multipart/form-data, 2257B]
//
// The exception therefore does NOT rest on the hostname. It requires that the
// destination equals the one the page's own presign response named, a multipart
// body with exactly one file part carrying the chosen resume's file name and only
// allowlisted form-field names, and only during the worker's own resume
// attachment, once. The guard cannot see the file's BYTES (Chromium omits File
// parts from request instrumentation); content identity is checked separately by
// hashing the file the browser holds in the input at selection time.
// ---------------------------------------------------------------------------

/** Page origins whose presign responses are trusted. EU and employer-hosted boards are not observed, so not included. */
export const GREENHOUSE_PAGE_ORIGINS = [
    'https://job-boards.greenhouse.io',
    'https://boards.greenhouse.io',
] as const;
/** The only bucket observed. Another employer or region using a different bucket is refused, not assumed. */
export const GREENHOUSE_S3_ORIGIN =
    'https://grnhse-prod-jben-us-east-1.s3.amazonaws.com';
export const GREENHOUSE_PRESIGN_RULE = 'greenhouse-presigned-upload';

export function greenhousePresignRule(
    pageOrigins: readonly string[],
): LearnRule {
    return {
        name: GREENHOUSE_PRESIGN_RULE,
        pageOrigins,
        pathSuffix: '/uncacheable_attributes/presigned_fields',
        // Only the destination is taken from the answer; the signed fields are never kept.
        destination: (json) => {
            const url = (json as { url?: unknown } | null)?.url;
            return typeof url === 'string' ? url : null;
        },
    };
}

export function greenhouseResumeUploadException(
    s3Origin: string,
    resume: ResumeContext,
): UploadException {
    return {
        name: 'greenhouse-resume-upload',
        method: 'POST',
        origin: s3Origin,
        pathPattern: /^\/$/,
        why: "Greenhouse's job-board widget POSTs the chosen resume, as multipart form data, to the S3 destination named by its own presigned_fields response; observed in the first Greenhouse run.",
        resourceTypes: ['xhr'],
        contentTypePrefix: 'multipart/form-data',
        duringUploadOnly: true,
        maxUses: 1,
        // The instrumented body holds the form fields only (the file part is omitted): a few hundred
        // bytes to ~2 KB observed. A generous but bounded allowance.
        maxBodyBytes: 32 * 1024,
        forbidQuery: true,
        authorizedBy: GREENHOUSE_PRESIGN_RULE,
        validateBody: (body, contentType) =>
            validateS3ResumePost(body, contentType, resume),
    };
}

export const greenhouse: Adapter = {
    name: 'greenhouse',
    formUrl: (url) => url, // the form is inline on the job page
    uploadExceptions: (origin, resume) =>
        (GREENHOUSE_PAGE_ORIGINS as readonly string[]).includes(origin)
            ? [greenhouseResumeUploadException(GREENHOUSE_S3_ORIGIN, resume)]
            : [],
    authorizationRules: (origin) =>
        (GREENHOUSE_PAGE_ORIGINS as readonly string[]).includes(origin)
            ? [greenhousePresignRule(GREENHOUSE_PAGE_ORIGINS)]
            : [],
    resumeUpload: { exceptionName: 'greenhouse-resume-upload' },
};

export function adapterFor(name: AtsName): Adapter {
    return name === 'lever' ? lever : greenhouse;
}
