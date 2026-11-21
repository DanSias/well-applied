<?php

/**
 * Deterministic fixtures shaped like Jobicy's DOCUMENTED responses (https://jobicy.com/jobs-rss-feed, read 2026-11-22). They are authored, not captured: the live service has not
 * been called, so field names and error behaviour beyond the documentation are assumptions.
 */
const JBC_KEY = 'jbc-test-key-SECRET-9f8e7d';

/** Jobicy's real jobSlug already contains the job id: "163636-some-title" (verified against the free tier 2026-11-22). */
function jbcSlug(int $id): string
{
    return $id.'-platform-engineer';
}

/** @return array<string, mixed> a free-tier job: `url` is always the Jobicy listing */
function jbcPublicJob(int $id, array $over = []): array
{
    return array_merge([
        'id' => $id, 'url' => 'https://jobicy.com/jobs/'.jbcSlug($id), 'jobSlug' => jbcSlug($id), 'jobTitle' => 'Platform Engineer '.$id, 'companyName' => 'Acme '.$id,
        'jobIndustry' => ['Engineering'], 'jobType' => ['Full-Time'], 'jobGeo' => 'USA', 'jobLevel' => 'Senior', 'jobExcerpt' => 'x', 'jobDescription' => '<p>SECRET DESCRIPTION</p>', 'pubDate' => '2026-11-20 10:00:00',
    ], $over);
}

/** @return array<string, mixed> the same job from the Commercial tier */
function jbcCommercialJob(int $id, ?string $destination, float $cost, array $over = []): array
{
    return jbcPublicJob($id, ['url' => $destination ?? 'https://jobicy.com/jobs/'.jbcSlug($id), 'job_request_cost' => $cost] + $over);
}

/**
 * Thirty matched ids and a few awkward ones, with a known answer:
 *  1001-1004 ATS (greenhouse, lever, ashby, workday)       1005-1007 employer-owned HTTPS        1008 aggregator          1009 tracker redirect
 *  1010 insecure http                                      1011 invalid                          1012-1017 no direct URL (still the listing, free)
 *  1018 repeat retrieval (different URL, no charge)        1019 slug that does not rebuild the listing (public url differs)
 *  1016 no jobSlug, 1017 jobSlug with another job's id prefix (both: not reconstructable)
 *  1020 public-only    2001 commercial-only    1021 duplicate id in the public page    (one record in each page has no id)    1022 identity mismatch
 *
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: float}
 */
function jbcPair(): array
{
    $dest = [
        1001 => 'https://job-boards.greenhouse.io/acme/jobs/1001?gh_jid=1001', 1002 => 'https://jobs.lever.co/acme/aaaaaaaa-1111-4111-8111-000000001002/apply',
        1003 => 'https://jobs.ashbyhq.com/acme/bbbbbbbb-2222-4222-8222-000000001003', 1004 => 'https://acme.wd5.myworkdayjobs.com/en-US/Careers/job/Remote/Engineer_R1004',
        1005 => 'https://careers.acme-corp.com/jobs/1005', 1006 => 'https://www.globex.com/careers/apply/1006', 1007 => 'https://jobs.initech.io/positions/1007',
        1008 => 'https://www.indeed.com/viewjob?jk=abc1008', 1009 => 'https://click.mailer.example/c?url=https%3A%2F%2Facme.com%2Fjobs%2F1009',
        1010 => 'http://careers.insecure.example/jobs/1010', 1011 => 'not a url at all',
    ];
    $public = [];
    $commercial = [];
    foreach ($dest as $id => $url) {
        $public[] = jbcPublicJob($id);
        $commercial[] = jbcCommercialJob($id, $url, 0.01);
    }
    // 1016 has no jobSlug at all and 1017's slug carries another id's prefix: neither can be reconstructed.
    $slugOverrides = [1016 => ['jobSlug' => null], 1017 => ['jobSlug' => '7777-platform-engineer']];
    foreach (range(1012, 1017) as $id) {
        $public[] = jbcPublicJob($id, $slugOverrides[$id] ?? []);
        $commercial[] = jbcCommercialJob($id, null, 0.0, $slugOverrides[$id] ?? []);
    }
    $public[] = jbcPublicJob(1018);
    $commercial[] = jbcCommercialJob(1018, 'https://boards.greenhouse.io/acme/jobs/1018', 0.0); // already paid for under this key: free
    $public[] = jbcPublicJob(1019, ['url' => 'https://jobicy.com/jobs/1019-an-older-slug']);   // the slug in the response does not rebuild this url
    $commercial[] = jbcCommercialJob(1019, 'https://careers.acme-corp.com/jobs/1019', 0.01, ['url' => 'https://careers.acme-corp.com/jobs/1019']);
    $public[] = jbcPublicJob(1020);                                       // only in the free page
    $commercial[] = jbcCommercialJob(2001, 'https://jobs.lever.co/acme/cccccccc-3333-4333-8333-000000002001', 0.01); // only in the commercial page
    $public[] = jbcPublicJob(1021);
    $public[] = jbcPublicJob(1021);                                       // a duplicated id
    $commercial[] = jbcCommercialJob(1021, 'https://jobs.lever.co/acme/dddddddd-4444-4444-8444-000000001021', 0.01);
    $public[] = jbcPublicJob(1022);
    $commercial[] = jbcCommercialJob(1022, 'https://jobs.lever.co/acme/eeeeeeee-5555-4555-8555-000000001022', 0.01, ['companyName' => 'A Different Company']);
    $public[] = ['jobTitle' => 'no id here', 'url' => 'https://jobicy.com/jobs/x'];           // malformed
    $commercial[] = ['id' => 9999, 'jobTitle' => 'no url here'];                               // malformed
    $commercial[] = 'not even an object';

    $total = round(array_sum(array_map(fn ($j) => is_array($j) ? ($j['job_request_cost'] ?? 0.0) : 0.0, $commercial)), 2);

    return [$public, $commercial, $total];
}

/** A documented-shape response envelope. */
function jbcEnvelope(array $jobs, ?float $totalCost = null, array $over = []): array
{
    return array_filter(array_merge([
        'success' => true, 'statusCode' => 200, 'jobCount' => count($jobs), 'jobs' => $jobs, 'nextCursor' => 'cursor-1', 'hasMore' => true, 'lastUpdate' => '2026-11-20 10:00:00', 'apiVersion' => '2.2.22',
        'total_request_cost' => $totalCost,
    ], $over), fn ($v) => $v !== null);
}
