// Pure: what do the independent facts about a resume upload add up to?
//
// Selecting a file, the page sending an upload request, the server accepting it, and the
// widget showing it are four different things. Each outcome names the weakest fact it rests
// on, and only `accepted_filename_visible` counts as verified. A response status is evidence
// the upload was accepted; it says nothing about whether an application was submitted.
import type { UploadOutcome, UploadResponseEvidence } from './contracts.ts';

export interface UploadFacts {
    /** The ATS has a separate upload step (an adapter declared an upload exception). */
    applicable: boolean;
    /** The file was handed to the page without error. */
    selected: boolean;
    /** SHA-256 of the exact bytes handed over equals the trusted resume's. */
    artifactSha256Matches: boolean;
    /** The guard let an upload request through (null = none was sent). */
    response: UploadResponseEvidence | null;
    /** An upload request was let through at all (it may still have no response). */
    requestAllowed: boolean;
    /** Upload-like requests the guard refused while the window was open. */
    refusals: number;
    filenameVisibleBefore: boolean;
    filenameVisible: boolean;
}

export const isAcceptedStatus = (status: number | null): boolean =>
    status !== null && status >= 200 && status < 300;

export function classifyUpload(f: UploadFacts): {
    outcome: UploadOutcome;
    detail: string;
} {
    if (!f.applicable)
        return {
            outcome: 'not_applicable',
            detail: 'this ATS attaches the file to the form; there is no separate upload step',
        };
    if (!f.selected)
        return {
            outcome: 'selection_failed',
            detail: 'the file could not be handed to the page',
        };
    if (!f.artifactSha256Matches)
        return {
            outcome: 'selection_failed',
            detail: 'the bytes handed to the page do not hash to the trusted resume',
        };
    if (f.refusals > 0)
        return {
            outcome: 'unauthorized_request',
            detail: `${f.refusals} upload-like request(s) were refused by the guard`,
        };
    if (!f.requestAllowed)
        return {
            outcome: 'no_upload_request',
            detail: 'the file was selected but the page sent no permitted upload request in the bounded wait',
        };
    const r = f.response;
    if (r === null || (r.status === null && r.failure === null))
        return {
            outcome: 'unknown',
            detail: 'the upload request was sent but no response arrived in the bounded wait',
        };
    if (r.failure !== null || !isAcceptedStatus(r.status))
        return {
            outcome: 'failed',
            detail:
                r.failure !== null
                    ? `the upload request failed at the network (${r.failure})`
                    : `the server answered the upload with HTTP ${r.status}`,
        };
    if (f.filenameVisible && !f.filenameVisibleBefore)
        return {
            outcome: 'accepted_filename_visible',
            detail: `the server accepted the upload (HTTP ${r.status}) and the widget shows the file name`,
        };
    return {
        outcome: 'accepted_confirmation_missing',
        detail: f.filenameVisibleBefore
            ? `the server accepted the upload (HTTP ${r.status}) but the file name was already on the page before it, so seeing it proves nothing`
            : `the server accepted the upload (HTTP ${r.status}) but the widget never showed the file name`,
    };
}
