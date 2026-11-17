// ATS-specific knowledge is limited to: how to recognise the ATS, and
// where its application form lives. Field discovery, classification,
// filling, verification and every safeguard are shared, so adding an ATS
// never touches the safety path.
import type { AtsName } from '../contracts.ts';
import type { UploadException } from '../guard.ts';

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
    uploadExceptions: () => [],
};

export const greenhouse: Adapter = {
    name: 'greenhouse',
    formUrl: (url) => url, // the form is inline on the job page
    uploadExceptions: () => [],
};

export function adapterFor(name: AtsName): Adapter {
    return name === 'lever' ? lever : greenhouse;
}
