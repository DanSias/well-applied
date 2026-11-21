// Derives the PROPOSED aggregator-resolution batch from the proposed Stage 1 cohort. Reads one committed file, writes one. No network,
// no database. The batch is a plan: it authorises nothing, and its hash is what a later approval would name.
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sha256, stableJson } from '../src/canon.js';

const dir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'cohort');
const cohortBytes = readFileSync(path.join(dir, 'stage1.proposed.json'));
const cohort = JSON.parse(cohortBytes);
const TRACKER_SHAPE = /^www\.adzuna\.[a-z.]+\/land\/ad\//;

const cases = cohort.cases
    .filter((c) => c.listing_url_kind === 'aggregator_listing')
    .sort((a, b) => a.id.localeCompare(b.id))
    .map((c) => ({
        id: c.id,
        job_posting_id: c.record_ref.job_posting_id,
        source: c.source,
        host: c.record_ref.host,
        url_shape: c.record_ref.url_shape,
        url_sha256: c.record_ref.url_sha256,
        needs_tracker_permission: TRACKER_SHAPE.test(c.record_ref.url_shape),
        duplicate_group: c.flags?.duplicate_group ?? null,
        hand_review_slot: c.ground_truth.plan === 'hand_review',
    }));

const batch = {
    schema: 'eval-resolution-batch/1',
    status: 'proposed',
    purpose: 'A PROPOSED supervised, read-only destination-resolution run over the aggregator-listing cases of the proposed Stage 1 cohort. It authorises nothing and has not run.',
    source_cohort: { file: 'eval/cohort/stage1.proposed.json', sha256: sha256(cohortBytes), cohort_version: cohort.cohort_version },
    resolver: { module: 'src/resolution/resolve.js', version: '0.1.0', allow_tracker_redirects: false },
    execution: {
        concurrency: 1,
        retries: 0,
        order: 'ascending by id, one case at a time, Adzuna (tracker) cases last',
        pacing: { min_ms_between_requests_to_the_same_host: 5000, min_ms_between_cases: 2000 },
        per_case: { max_pages: 3, max_follows: 2, max_redirects: 8, budget_ms: 90000 },
        per_host_request_cap: { source_listing_host: 'one navigation per case', destination_host: 'two navigations per case' },
        total_page_navigation_cap: cases.length * 3,
        wall_clock_cap_minutes: 20,
    },
    stop_conditions: [
        'any security refusal: a destination, redirect or Apply link refused by the guard policy (private address, disallowed host, redirect limit)',
        'HTTP 403 or 429, or a CAPTCHA/anti-bot page, on two cases from the same host (stop that host); on any three cases overall (stop the run)',
        'two consecutive infrastructure failures (timeouts, connection errors)',
        'the total page-navigation cap or the wall-clock cap',
        'any request that is not a GET, or any attempt to interact with a page',
        'a changed url_sha256 when the URL is re-read from the database by id',
    ],
    human_review: ['every unresolved:multiple_apply_links', 'every unresolved:unknown_redirector_requires_review (an unknown redirector or click-through is flagged before navigation, never silently followed)', 'every proposed_by_resolution with needs_human_review', 'every unresolved:too_many_steps and unclassified stop', 'a seeded 20% spot-check of the automatic proposals', 'every case in a hand_review_slot'],
    cases,
};
const out = stableJson(batch);
writeFileSync(path.join(dir, 'resolution-batch.proposed.json'), out);
console.log(`wrote resolution-batch.proposed.json: ${cases.length} cases (${cases.filter((c) => c.needs_tracker_permission).length} need tracker permission), sha256 ${sha256(out)}`);

// The smallest supervised pilot: three cases from the batch, none needing tracker permission. Same format, so the same runner validates it. A proposal.
const PILOT_IDS = ['prod-180', 'prod-393', 'prod-83'];
const pilotCases = cases.filter((c) => PILOT_IDS.includes(c.id));
if (pilotCases.length !== 3 || pilotCases.some((c) => c.needs_tracker_permission)) throw new Error('the pilot cases must exist and not need tracker permission');
const pilot = {
    ...batch,
    purpose: 'A PROPOSED supervised live PILOT: three of the batch cases, read-only, with tighter limits. It authorises nothing and has not run.',
    pilot_of: { file: 'eval/cohort/resolution-batch.proposed.json', sha256: sha256(out), case_ids: PILOT_IDS },
    execution: { ...batch.execution, order: 'ascending by id, one case at a time', pacing: { min_ms_between_requests_to_the_same_host: 10000, min_ms_between_cases: 2000 }, total_page_navigation_cap: 9, wall_clock_cap_minutes: 8 },
    cases: pilotCases,
};
const pilotOut = stableJson(pilot);
writeFileSync(path.join(dir, 'resolution-pilot.proposed.json'), pilotOut);
console.log(`wrote resolution-pilot.proposed.json: ${pilotCases.length} cases, sha256 ${sha256(pilotOut)}`);

// The same pilot WITHOUT the Himalayas case: Himalayas' terms prohibit automated access to its pages except through its API (see eval/live/AUTOMATION-RESTRICTIONS.md).
const jobicyCases = pilotCases.filter((c) => c.source === 'jobicy');
const jobicyPilot = { ...pilot, purpose: 'A PROPOSED supervised live PILOT limited to Jobicy (two cases): Himalayas is excluded because its terms prohibit automated page access. It authorises nothing and has not run.', pilot_of: { ...pilot.pilot_of, case_ids: jobicyCases.map((c) => c.id) }, execution: { ...pilot.execution, total_page_navigation_cap: 6, wall_clock_cap_minutes: 6 }, cases: jobicyCases };
const jobicyOut = stableJson(jobicyPilot);
writeFileSync(path.join(dir, 'resolution-pilot-jobicy-only.proposed.json'), jobicyOut);
console.log(`wrote resolution-pilot-jobicy-only.proposed.json: ${jobicyCases.length} cases, sha256 ${sha256(jobicyOut)}`);
