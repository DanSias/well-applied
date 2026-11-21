// Builds the dry-run batch: the SAME manifest format as the proposed batch, over LOCAL FIXTURE URLs only (logical hosts such as www.adzuna.com that the
// dry run maps to local servers inside the browser). Nothing here names a real job. Regenerate with: node eval/runner/fixtures/make-dry-run-batch.mjs
import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sha256, stableJson } from '../../src/canon.js';

const cases = [
    ['d-01-jobicy-success', 'jobicy', 'http://jobicy.com/jobs/101', false, 'Jobicy listing, employer page, then a Greenhouse form'],
    ['d-02-ambiguous-links', 'jobicy', 'http://jobicy.com/jobs/103', false, 'two different Apply destinations'],
    ['d-03-himalayas-login', 'himalayas', 'http://himalayas.app/companies/acme/jobs/to-login', false, 'Apply leads to a sign-in wall'],
    ['d-04-direct-greenhouse', 'manual', 'http://job-boards.greenhouse.io/acme/jobs/1', false, 'a direct ATS link'],
    ['d-05-unknown-redirector', 'jobicy', 'http://jobicy.com/jobs/202', false, 'an Apply link through an unknown redirector'],
    ['d-06-adzuna-tracker', 'adzuna', 'http://www.adzuna.com/land/ad/1001?se=x', true, 'an Adzuna click-through (needs tracker permission)'],
    ['d-07-no-apply-link', 'jobicy', 'http://jobicy.com/jobs/102', false, 'no Apply link'],
    ['d-08-dead-destination', 'himalayas', 'http://himalayas.app/companies/acme/jobs/to-dead', false, 'Apply leads to a 404'],
    ['d-09-private-apply', 'jobicy', 'http://jobicy.com/jobs/104', false, 'an Apply link to a private address (a security refusal: stops the batch)'],
    ['d-10-after-the-stop', 'jobicy', 'http://jobicy.com/jobs/101', false, 'would be fine, but the batch has stopped'],
].map(([id, source, logical, tracker, note]) => {
    const u = new URL(logical);

    return { id, source, host: u.hostname, url_shape: `${u.hostname}${u.pathname.replace(/\d+/g, 'N')}${u.search ? '?…' : ''}`, url_sha256: sha256(logical), needs_tracker_permission: tracker, duplicate_group: null, hand_review_slot: false, fixture: { logical_url: logical, note } };
});

const batch = {
    schema: 'eval-resolution-batch/1',
    status: 'proposed',
    purpose: 'A DRY-RUN batch over local fixtures only. Same format as the proposed batch; authorises nothing.',
    source_cohort: { file: 'none (fixtures)', sha256: sha256('dry-run fixtures'), cohort_version: 'dry-run' },
    resolver: { module: 'src/resolution/resolve.js', version: '0.1.0', allow_tracker_redirects: false },
    execution: {
        concurrency: 1,
        retries: 0,
        order: 'ascending by id, tracker cases last',
        pacing: { min_ms_between_requests_to_the_same_host: 30, min_ms_between_cases: 0 },
        per_case: { max_pages: 3, max_follows: 2, max_redirects: 8, budget_ms: 60000 },
        total_page_navigation_cap: 30,
        wall_clock_cap_minutes: 5,
    },
    stop_conditions: ['any security refusal', 'two consecutive infrastructure failures', 'a changed source URL hash'],
    human_review: ['every unknown redirector', 'every ambiguous result'],
    cases,
};
const out = stableJson(batch);
writeFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), 'dry-run-batch.json'), out);
console.log(`dry-run-batch.json sha256 ${sha256(out)}`);
