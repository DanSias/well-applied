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
        'any security refusal that is not an expected policy refusal of a prohibited destination',
        'HTTP 403 or 429, or a CAPTCHA/anti-bot page, on two cases from the same host (stop that host); on any three cases overall (stop the run)',
        'two consecutive infrastructure failures (timeouts, connection errors)',
        'the total page-navigation cap or the wall-clock cap',
        'any request that is not a GET, or any attempt to interact with a page',
        'a changed url_sha256 when the URL is re-read from the database by id',
    ],
    human_review: ['every unresolved:multiple_apply_links', 'every proposed_by_resolution with needs_human_review', 'every unresolved:too_many_steps and unclassified stop', 'a seeded 20% spot-check of the automatic proposals', 'every case in a hand_review_slot'],
    cases,
};
const out = stableJson(batch);
writeFileSync(path.join(dir, 'resolution-batch.proposed.json'), out);
console.log(`wrote resolution-batch.proposed.json: ${cases.length} cases (${cases.filter((c) => c.needs_tracker_permission).length} need tracker permission), sha256 ${sha256(out)}`);
