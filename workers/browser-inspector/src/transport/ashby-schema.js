// Fetches and adapts one Ashby job's form schema: parse the person's link, send the two approved operations through the
// transport, validate both responses, map them, and reduce to the existing worker->Laravel contract. Every step reuses the
// offline adapter unchanged (target, request, response, map, contract); this file only connects it to the transport.
//
// Returns a value, never throws for an expected refusal:
//   { applicable: false }                                   the link is not a supported Ashby job link (the caller does nothing)
//   { applicable: true, ok: true, contract, coverage, ... } a schema, as the contract result
//   { applicable: true, ok: false, kind, stage, reason }    kind 'availability' (may fall back) or 'security' (must fail closed)
import { parseAshbyJobUrl } from '../providers/ashby/target.js';
import { validateOrganizationResponse, validateJobPostingResponse } from '../providers/ashby/response.js';
import { mapAshbyForm } from '../providers/ashby/map.js';
import { toContractResult, AshbyContractError } from '../providers/ashby/contract.js';
import { AshbyTransportError } from './ashby.js';

// Reasons that mean the server answered about a DIFFERENT job or organization than the one asked for, or a body past its limit:
// not a missing or changed schema, so not something to paper over with a fallback.
const SECURITY_RESPONSE_REASONS = new Set(['job_id_mismatch', 'organization_mismatch', 'response_too_large']);

const failure = (kind, stage, reason, detail = null) => ({ applicable: true, ok: false, kind, stage, reason, detail });

/**
 * @param {string} url the claim's start URL
 * @param {{send: Function}} transport
 */
export async function fetchAshbySchema(url, transport) {
    const target = parseAshbyJobUrl(url);
    if (!target.ok) return { applicable: false, reason: target.reason };

    const { organizationHostedJobsPageName, jobPostingId } = target;
    let organizationResponse;
    let jobPostingResponse;
    let ms = 0;
    let stage = 'organization';
    try {
        const org = await transport.send('ApiOrganizationFromHostedJobsPageName', { organizationHostedJobsPageName, searchContext: 'JobPosting' });
        stage = 'job_posting';
        const job = await transport.send('ApiJobPosting', { organizationHostedJobsPageName, jobPostingId });
        organizationResponse = org.json;
        jobPostingResponse = job.json;
        ms = org.ms + job.ms;
    } catch (error) {
        if (error instanceof AshbyTransportError) return failure(error.kind, stage, error.reason, error.detail);
        throw error;
    }

    const organization = validateOrganizationResponse(organizationResponse, target);
    if (!organization.ok) return failure(SECURITY_RESPONSE_REASONS.has(organization.reason) ? 'security' : 'availability', 'organization', organization.reason, organization.detail);
    const job = validateJobPostingResponse(jobPostingResponse, target);
    if (!job.ok) return failure(SECURITY_RESPONSE_REASONS.has(job.reason) ? 'security' : 'availability', 'job_posting', job.reason, job.detail);

    const mapped = mapAshbyForm({ jobPosting: job.jobPosting, organization: organization.organization });
    let contract;
    try {
        contract = toContractResult(mapped);
    } catch (error) {
        if (error instanceof AshbyContractError) return failure('availability', 'contract', error.reason, null);
        throw error;
    }

    return { applicable: true, ok: true, contract, coverage: mapped.coverage, ms };
}
