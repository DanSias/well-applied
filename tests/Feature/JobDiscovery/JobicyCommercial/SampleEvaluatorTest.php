<?php

use App\Support\JobDiscovery\JobicyCommercial\DestinationClassifier;
use App\Support\JobDiscovery\JobicyCommercial\SampleEvaluator;
use App\Support\JobDiscovery\JobicyCommercial\UrlShape;
use App\Support\JobDiscovery\JobicyCommercial\Wilson;

require_once __DIR__.'/JobicyFixtures.php';

function jbcEvaluate(): array
{
    [$public, $commercial, $total] = jbcPair();

    return (new SampleEvaluator)->evaluate($public, $commercial, ['commercial_total_cost_usd' => $total]);
}

// ---- classification -----------------------------------------------------------------------------------------------------------------------

it('classifies a destination by its text: genuine ATS and employer URLs, and everything that is not one', function (string $url, string $class, ?string $platform) {
    $c = DestinationClassifier::classify($url, 'https://jobicy.com/jobs/1-x');
    expect([$c['class'], $c['platform']])->toBe([$class, $platform]);
})->with([
    ['https://job-boards.greenhouse.io/acme/jobs/1?gh_jid=1&gclid=z', 'genuine_ats', 'greenhouse'],
    ['https://jobs.lever.co/acme/uuid/apply', 'genuine_ats', 'lever'],
    ['https://jobs.ashbyhq.com/acme/uuid', 'genuine_ats', 'ashby'],
    ['https://acme.wd5.myworkdayjobs.com/en-US/Careers/job/x', 'genuine_ats', 'workday'],
    ['https://apply.workable.com/acme/j/ABC/', 'genuine_ats', 'workable'],
    ['https://jobs.smartrecruiters.com/Acme/1-x', 'genuine_ats', 'smartrecruiters'],
    ['https://acme.eightfold.ai/careers/job/1', 'genuine_ats', 'eightfold'],
    ['https://careers.acme.com/jobs/42?utm_source=jobicy&next=%2Fapply', 'genuine_employer', null],
    ['https://www.acme.com/out/engineer-123', 'genuine_employer', null],
    ['https://jobicy.com/jobs/2-other', 'jobicy_listing', null],
    ['https://www.indeed.com/viewjob?jk=1', 'aggregator', null],
    ['https://himalayas.app/companies/acme/jobs/x', 'aggregator', null],
    ['https://click.mailer.example/c?url=https%3A%2F%2Facme.com%2F1', 'tracker_redirect', null],
    ['https://links.example.net/redirect?to=https://acme.com/1', 'tracker_redirect', null],
    ['https://www.adzuna.com/land/ad/123?se=x', 'tracker_redirect', null],
    ['http://careers.acme.com/jobs/1', 'insecure_http', null],
    ['https://user:pw@careers.acme.com/jobs/1', 'invalid', null],
    ['https://localhost/apply', 'invalid', null],
    ['not a url', 'invalid', null],
]);

it('treats the listing itself as not a destination, and never flags a normal ATS or employer URL as a tracker', function () {
    expect(DestinationClassifier::classify('https://jobicy.com/jobs/1-x?utm_source=a', 'https://jobicy.com/jobs/1-x')['class'])->toBe('jobicy_listing');
    foreach (['https://boards.greenhouse.io/embed/job_app?for=acme&token=9&redirect=https%3A%2F%2Facme.com%2F', 'https://jobs.lever.co/acme/1/apply?lever-source=jobicy'] as $url) {
        expect(DestinationClassifier::classify($url)['class'])->toBe('genuine_ats');
    }
});

it('reduces a URL to host and path shape for reports', function () {
    expect(UrlShape::of('https://jobs.example.com:8443/en-US/careers/job/1234567/senior-data-scientist?token=SECRET#x'))->toBe('jobs.example.com/en-us/careers/job/N/<slug>')
        ->and(UrlShape::of('https://jobs.lever.co/acme/aaaaaaaa-1111-4111-8111-000000001002/apply'))->toBe('jobs.lever.co/<slug>/<id>/apply')
        ->and(UrlShape::of('not a url'))->toBeNull();
});

it('computes a Wilson interval and says nothing for an empty sample', function () {
    expect(Wilson::interval(0, 0))->toBeNull();
    $i = Wilson::interval(8, 10);
    expect($i['low'])->toBeLessThan(0.8)->and($i['high'])->toBeGreaterThan(0.8)->and($i)->toBe(['low' => 0.4902, 'high' => 0.9433]);
});

// ---- the comparison -----------------------------------------------------------------------------------------------------------------

it('matches the two tiers by stable id and accounts for every record exactly once', function () {
    $r = jbcEvaluate();

    expect($r['totals'])->toBe([
        'public_returned' => 24, 'commercial_returned' => 24, 'matched' => 19,
        'unmatched_public_only' => 1, 'unmatched_commercial_only' => 1, 'ambiguous' => 2,
        'malformed_public' => 1, 'malformed_commercial' => 2,
    ])->and($r['unmatched'])->toBe(['public_only' => ['1020'], 'commercial_only' => ['2001']])
        ->and($r['ambiguous'])->toBe([['id' => '1021', 'reason' => 'duplicate_id'], ['id' => '1022', 'reason' => 'identity_mismatch']])
        ->and($r['malformed'])->toBe(['public' => [['index' => 23, 'reason' => 'missing_id']], 'commercial' => [['index' => 22, 'reason' => 'missing_url'], ['index' => 23, 'reason' => 'not_an_object']]]);
});

it('reports which jobs reach genuine destinations and which still point at Jobicy, aggregators or trackers', function () {
    $d = jbcEvaluate()['destinations'];

    expect($d['genuine'])->toBe(9)    // ATS 1001-1004 and the repeat 1018; employer-owned 1005-1007 and 1019
        ->and($d['by_class'])->toBe(['aggregator' => 1, 'genuine_ats' => 5, 'genuine_employer' => 4, 'insecure_http' => 1, 'invalid' => 1, 'jobicy_listing' => 6, 'tracker_redirect' => 1])
        ->and($d['still_listing_aggregator_or_tracker'])->toBe(8)
        ->and($d['ats_platforms'])->toBe(['ashby' => 1, 'greenhouse' => 2, 'lever' => 1, 'workday' => 1])
        ->and($d['direct_url_jobs'])->toBe(13);
});

it('reports the two direct-URL signals separately and flags where they disagree', function () {
    $r = jbcEvaluate();

    expect($r['destinations']['direct_signals_agreement'])->toBe(['cost_and_url' => 12, 'cost_only' => 0, 'url_only' => 1, 'neither' => 6])
        ->and(collect($r['warnings'])->contains(fn ($w) => str_starts_with($w, 'direct_signals_disagree')))->toBeTrue();
    $repeat = collect($r['jobs'])->firstWhere('id', '1018');
    expect($repeat)->toMatchArray(['direct_by_cost' => false, 'direct_by_url' => true, 'cost_cents' => 0, 'genuine' => true]);
});

it('measures how often the listing URL can be rebuilt from jobSlug (which already contains the id), and never guesses when it cannot', function () {
    $l = jbcEvaluate()['listing_reconstruction'];

    expect($l)->toMatchArray(['checked' => 17, 'correct' => 16, 'accuracy' => 0.9412, 'misses' => ['1019']])
        ->and($l['not_reconstructable'])->toBe(['count' => 2, 'missing_slug' => ['1016'], 'inconsistent_slug_prefix' => ['1017']])
        ->and($l['rule'])->toContain('jobicy.com/jobs/{jobSlug}')->not->toContain('{id}-{jobSlug}');
    $rows = collect(jbcEvaluate()['jobs']);
    expect($rows->firstWhere('id', '1016')['listing_reconstructed'])->toBeNull()->and($rows->firstWhere('id', '1017')['listing_reconstructed'])->toBeNull()->and($rows->firstWhere('id', '1019')['listing_reconstructed'])->toBeFalse()->and($rows->firstWhere('id', '1001')['listing_reconstructed'])->toBeTrue();
});

it('reconstructs the real live shape: jobSlug "163636-some-title" gives https://jobicy.com/jobs/163636-some-title', function () {
    $pub = [['id' => 163636, 'url' => 'https://jobicy.com/jobs/163636-vp-example', 'jobSlug' => '163636-vp-example', 'jobTitle' => 'T', 'companyName' => 'C']];
    $com = [['id' => 163636, 'url' => 'https://jobicy.com/jobs/163636-vp-example', 'jobSlug' => '163636-vp-example', 'jobTitle' => 'T', 'companyName' => 'C', 'job_request_cost' => 0]];

    $l = (new SampleEvaluator)->evaluate($pub, $com, [])['listing_reconstruction'];
    expect([$l['checked'], $l['correct'], $l['accuracy'], $l['not_reconstructable']['count']])->toBe([1, 1, 1.0, 0]);

    // The id-prefixed form is not rebuilt as {id}-{jobSlug}: that doubled id would be wrong.
    $wrong = [['id' => 5, 'url' => 'https://jobicy.com/jobs/5-5-x', 'jobSlug' => '5-x', 'jobTitle' => 'T', 'companyName' => 'C']];
    expect((new SampleEvaluator)->evaluate($wrong, $wrong, [])['listing_reconstruction']['correct'])->toBe(0);
});

it('does not treat a slug that is only the id, or that has odd characters, as reconstructable', function (string $slug) {
    $job = [['id' => 9, 'url' => 'https://jobicy.com/jobs/9-x', 'jobSlug' => $slug, 'jobTitle' => 'T', 'companyName' => 'C']];

    $l = (new SampleEvaluator)->evaluate($job, $job, [])['listing_reconstruction'];
    expect([$l['checked'], $l['not_reconstructable']['count']])->toBe([0, 1]);
})->with(['9-', '9', '99-x', '9-x/../y', '9-x?z=1', '9-x y']);

it('reconciles API-reported charges and prices a usable destination', function () {
    [$public, $commercial, $total] = jbcPair();
    $c = (new SampleEvaluator)->evaluate($public, $commercial, ['commercial_total_cost_usd' => $total])['charges'];

    expect($c['commercial_tier_observed'])->toBeTrue()
        ->and($c['api_reported_total_usd'])->toBe($total)
        ->and($c['sum_of_job_charges_usd'])->toBe(0.12, 'twelve jobs charged a cent each; 1018 was a free repeat')
        ->and($c['jobs_with_a_reported_charge'])->toBe(19)
        ->and($c['cost_per_genuine_destination_usd'])->toBe(round($total / 9, 4))
        ->and($c['projected_first_retrieval_per_1000_jobs_usd'])->toBe(round($total / 19 * 1000, 2));

    $mismatch = (new SampleEvaluator)->evaluate($public, $commercial, ['commercial_total_cost_usd' => 5.0]);
    expect(collect($mismatch['warnings'])->contains(fn ($w) => str_starts_with($w, 'charge_mismatch')))->toBeTrue();
});

it('says when the Commercial tier was not observed (the API documents no error for a bad key)', function () {
    $public = [jbcPublicJob(1), jbcPublicJob(2)];
    $silent = [jbcPublicJob(1), jbcPublicJob(2)];       // exactly what a free-tier answer to a bad key might look like: no cost field, same URLs

    $r = (new SampleEvaluator)->evaluate($public, $silent, []);

    expect($r['charges']['commercial_tier_observed'])->toBeFalse()->and($r['charges']['api_reported_total_usd'])->toBeNull()->and($r['charges']['cost_per_genuine_destination_usd'])->toBeNull()
        ->and($r['warnings'][0])->toStartWith('commercial_tier_not_observed')->and($r['destinations']['genuine'])->toBe(0);
});

it('never reads the window as the historical inventory', function () {
    $s = jbcEvaluate()['scope'];

    expect($s['historical_inventory'])->toBeFalse()->and($s['note'])->toContain('older than seven days')->toContain('stored Jobicy jobs')->and($s['live_verified'])->toContain('not by this evaluator');
});

it('hands each genuine candidate to the B1 contract: Jobicy listing preserved, commercial provenance, unverified, attribution kept', function () {
    $e = (new SampleEvaluator)->evaluate(...[...array_slice(jbcPair(), 0, 2), ['commercial_total_cost_usd' => 0.12]]);
    $candidate = $e['_candidates']['1001'];

    expect($candidate['listing_url'])->toBe('https://jobicy.com/jobs/1001-platform-engineer')
        ->and($candidate['destinations'][0])->toMatchArray(['role' => 'candidate_destination', 'source' => 'jobicy', 'verification' => ['status' => 'unverified']])
        ->and($candidate['destinations'][0]['provenance'])->toBe(['origin' => 'provider_api', 'field' => 'url', 'tier' => 'commercial'])
        ->and($candidate['destinations'][0]['usage']['may_replace_apply_link'])->toBeFalse()
        ->and($candidate['attribution'])->toMatchArray(['provider' => 'jobicy', 'canonical_url' => 'https://jobicy.com/jobs/1001-platform-engineer', 'required' => true])
        ->and($e['b1_contract']['candidates_accepted'])->toBe(9);
    // The repeat (1018) and a job whose slug does not rebuild (1019) are still accepted: the contract judges the destination, not the slug.
    $ids = array_map('strval', array_keys($e['_candidates']));
    expect($ids)->toContain('1018', '1019')->not->toContain('1012', '1008', '1009', '1010', '1011');
});

it('produces a sanitised report: no query string, port, slug, title, company, description, credential or raw URL', function () {
    $json = json_encode(array_diff_key(jbcEvaluate(), ['_candidates' => 1]), JSON_UNESCAPED_SLASHES);

    expect($json)->not->toContain('gh_jid')->not->toContain('SECRET')->not->toContain('Platform Engineer')->not->toContain('Acme ')->not->toContain('?')->not->toContain('://')->not->toContain(JBC_KEY)
        ->and($json)->toContain('job-boards.greenhouse.io/<slug>/jobs/N')->toContain('click.mailer.example/<slug>');
});

it('is deterministic and pure: the same pages give the same report', function () {
    expect(json_encode(jbcEvaluate()))->toBe(json_encode(jbcEvaluate()));
});

it('handles empty and one-sided pages without dividing by zero', function () {
    $r = (new SampleEvaluator)->evaluate([], [], []);
    expect($r['totals']['matched'])->toBe(0)->and($r['destinations']['genuine_share_of_matched'])->toBeNull()->and($r['destinations']['genuine_share_interval_95'])->toBeNull()->and($r['listing_reconstruction']['accuracy'])->toBeNull();

    $only = (new SampleEvaluator)->evaluate([jbcPublicJob(1)], [], []);
    expect($only['totals'])->toMatchArray(['public_returned' => 1, 'matched' => 0, 'unmatched_public_only' => 1]);
});
