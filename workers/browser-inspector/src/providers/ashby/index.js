// Public surface of the OFFLINE Ashby adapter. Pure functions only: nothing here opens a connection, reads a file or touches a
// page. A future transport (a separate module, behind its own approval) would call buildRequest(), send exactly that body, and
// hand the parsed JSON back to adaptResponses(); none of the pieces below need to change when it arrives.
import { parseAshbyJobUrl } from './target.js';
import { validateOrganizationResponse, validateJobPostingResponse } from './response.js';
import { mapAshbyForm } from './map.js';

export { parseAshbyJobUrl } from './target.js';
export { buildRequest, validateRequestBody, AshbyRequestError } from './request.js';
export { validateOrganizationResponse, validateJobPostingResponse, MAX_RESPONSE_CHARS } from './response.js';
export { mapAshbyForm } from './map.js';
export { APPROVED_OPERATIONS, hashDocument } from './documents.js';

/**
 * Both responses for one job link -> normalized controls, or the first refusal with where it happened.
 * @returns {{ok: true, result: object} | {ok: false, stage: string, reason: string, detail: string|null}}
 */
export function adaptResponses({ url, organizationResponse, jobPostingResponse }) {
    const target = parseAshbyJobUrl(url);
    if (!target.ok) return { ok: false, stage: 'target', reason: target.reason, detail: null };

    const org = validateOrganizationResponse(organizationResponse, target);
    if (!org.ok) return { ok: false, stage: 'organization', reason: org.reason, detail: org.detail };
    const job = validateJobPostingResponse(jobPostingResponse, target);
    if (!job.ok) return { ok: false, stage: 'job_posting', reason: job.reason, detail: job.detail };

    return { ok: true, result: mapAshbyForm({ jobPosting: job.jobPosting, organization: org.organization }) };
}
