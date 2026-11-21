// The proposed aggregator-resolution batch: derived from the proposed cohort, hash-named in its plan, and authorising nothing.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sha256 } from '../eval/src/canon.js';

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const file = (f) => path.join(ROOT, 'eval', 'cohort', f);
const batchBytes = readFileSync(file('resolution-batch.proposed.json'));
const batch = JSON.parse(batchBytes);
const cohort = JSON.parse(readFileSync(file('stage1.proposed.json'), 'utf8'));
const plan = readFileSync(file('RESOLUTION-EVALUATION-PLAN.md'), 'utf8');

test('the plan names the batch by its hash, and the batch names the cohort it was derived from by its hash', () => {
    assert.ok(plan.includes(sha256(batchBytes)), 'the plan quotes the current batch hash');
    assert.equal(batch.source_cohort.sha256, sha256(readFileSync(file('stage1.proposed.json'))));
});

test('it covers exactly the 13 aggregator-listing cases of the cohort, one at a time, with no retries', () => {
    const aggs = cohort.cases.filter((c) => c.listing_url_kind === 'aggregator_listing').map((c) => c.id).sort();
    assert.deepEqual(batch.cases.map((c) => c.id), aggs);
    assert.equal(batch.cases.length, 13);
    assert.deepEqual([batch.execution.concurrency, batch.execution.retries], [1, 0]);
    assert.equal(batch.execution.total_page_navigation_cap, 39);
    assert.ok(batch.execution.pacing.min_ms_between_requests_to_the_same_host >= 5000);
});

test('it has explicit stop conditions and human review, and it is only a proposal', () => {
    assert.ok(batch.stop_conditions.length >= 6);
    assert.ok(batch.stop_conditions.some((s) => /403 or 429/.test(s)) && batch.stop_conditions.some((s) => /not a GET/.test(s)));
    assert.ok(batch.human_review.some((s) => /multiple_apply_links/.test(s)));
    assert.equal(batch.status, 'proposed');
    for (const k of ['authorised', 'authorized', 'approved', 'run']) assert.equal(k in batch, false);
    assert.equal(batch.resolver.allow_tracker_redirects, false, 'tracker redirects are off unless separately approved');
});

test('every Adzuna case is flagged as needing tracker permission, and no other case is', () => {
    for (const c of batch.cases) assert.equal(c.needs_tracker_permission, c.source === 'adzuna', c.id);
    assert.equal(batch.cases.filter((c) => c.needs_tracker_permission).length, 5);
});

test('the batch copies no URL, title, company or description', () => {
    for (const c of batch.cases) {
        assert.deepEqual(Object.keys(c).sort(), ['duplicate_group', 'hand_review_slot', 'host', 'id', 'job_posting_id', 'needs_tracker_permission', 'source', 'url_shape', 'url_sha256'].sort());
        assert.equal(/https?:\/\//.test(JSON.stringify(c)), false, c.id);
    }
});
