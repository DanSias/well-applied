// ATS-specific knowledge is limited to: how to recognise the ATS, and
// where its application form lives. Field discovery, classification,
// filling, verification and every safeguard are shared, so adding an ATS
// never touches the safety path.
import type { AtsName } from '../contracts.ts';
import type { UploadException } from '../request-policy.ts';

export interface Adapter {
    name: AtsName;
    /** A deterministic GET-only rewrite to the page that holds the form. */
    formUrl(url: URL): URL;
    /**
     * Non-GET requests the form legitimately makes while attaching a resume.
     * Empty until a supervised run observes a real one (see README); every
     * entry needs a justification and a test.
     */
    uploadExceptions(origin: string): UploadException[];
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
};

export const greenhouse: Adapter = {
    name: 'greenhouse',
    formUrl: (url) => url, // the form is inline on the job page
    uploadExceptions: () => [],
};

export function adapterFor(name: AtsName): Adapter {
    return name === 'lever' ? lever : greenhouse;
}
