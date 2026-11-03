<?php

use App\Models\Application;
use App\Models\ClarificationQuestion;
use App\Models\JobPosting;
use App\Models\MarketSample;
use App\Models\MarketSampleExclusion;
use App\Models\MarketSamplePosting;
use App\Support\ResumeProfile\BuildResumeProfile;
use Tests\Support\ResumeProfileFixtures as F;

/** A build with one clear full-stack role and one infrastructure-flavoured generic role. */
function reviewedBuild(): array
{
    $f = F::career();
    $providers = F::providers([
        'himalayas' => [
            F::candidate('himalayas', 'fs', 'Fullstack Co', 'Senior Full Stack Engineer', F::description(['React and Node.js.', 'Strong REST API design.'], unique: 'fs')),
            F::candidate('himalayas', 'gen', 'Generic Co', 'Senior Software Engineer', F::description(['React on the front end.', 'Python APIs on the back end.', 'Experience with Kubernetes and Helm.'], unique: 'gen')),
            F::candidate('himalayas', 'k8s', 'Cluster Co', 'Senior Software Engineer, Product', F::description(['TypeScript and Node.js services.', 'Run Kubernetes at scale.'], unique: 'k8s')),
        ],
        'jobicy' => [],
    ]);
    app(BuildResumeProfile::class)->run($f['profile'], pilot: true);

    return [...$f, 'providers' => $providers];
}

function postingNamed(string $company): MarketSamplePosting
{
    return MarketSamplePosting::where('company', $company)->latest('id')->firstOrFail();
}

it('shows, for each sampled posting, why it was included, its role-family signals and whether the description was complete', function () {
    $f = reviewedBuild();

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.sample.postings', function ($postings) {
            $p = collect($postings)->keyBy('company');

            return $p['Fullstack Co']['basis'] === 'title'
                && str_contains($p['Fullstack Co']['why_included'], 'full-stack')
                && $p['Generic Co']['basis'] === 'description'
                && str_contains($p['Generic Co']['why_included'], 'front-end (react')
                && in_array('python', $p['Generic Co']['backend'], true)
                && $p['Generic Co']['description']['complete'] === true
                && $p['Generic Co']['description']['chars'] > 600
                && $p['Generic Co']['provider'] === 'himalayas'
                && $p['Generic Co']['source_url'] === 'https://jobs.example/himalayas/gen';
        }));
});

it('removes a posting from the sample, recalculates the capability map and re-ranks, and leaves tracking alone', function () {
    $f = reviewedBuild();
    $jobs = JobPosting::count();
    $before = MarketSample::latest('id')->first()->aggregate['capabilities']['kubernetes'];
    expect($before['postings'])->toBe(2)
        ->and(ClarificationQuestion::where('topic_key', 'kubernetes')->sole()->question_text)->toContain('2 of 3');

    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed('Generic Co')]), ['reason' => 'More of an infrastructure role'])->assertRedirect();

    $sample = MarketSample::latest('id')->first();
    $removed = postingNamed('Generic Co');
    expect($removed->included)->toBeFalse()
        ->and($removed->decision)->toBe('user_excluded')
        ->and($removed->decision_detail)->toContain('More of an infrastructure role')
        ->and($sample->aggregate['postings'])->toBe(2)
        ->and($sample->aggregate['capabilities']['kubernetes']['postings'])->toBe(1)
        ->and($sample->aggregate['capabilities'])->not->toHaveKey('python_backend')
        ->and($sample->decision_counts['user_excluded'])->toBe(1)
        // One posting is below the minimum for a topic, so its question is retired rather than left stale.
        ->and(ClarificationQuestion::where('topic_key', 'kubernetes')->sole()->status)->toBe('retired')
        ->and(JobPosting::count())->toBe($jobs)
        ->and(Application::count())->toBe(0)
        ->and($f['profile']->fresh()->revision)->toBeGreaterThan(2);
});

it('retires an interview question when the removal makes the topic too rare, but never touches answered ones', function () {
    $f = reviewedBuild();
    $python = ClarificationQuestion::where('topic_key', 'python_backend')->first();

    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed('Generic Co')]));
    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed('Cluster Co')]));

    expect(ClarificationQuestion::where('topic_key', 'kubernetes')->first()?->status)->toBe('retired');
    if ($python !== null) {
        expect($python->fresh()->status)->toBe('retired');
    }
});

it('keeps a removed posting out of every later rebuild, even when the provider reposts it under a new id', function () {
    $f = reviewedBuild();
    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed('Generic Co')]));
    expect(MarketSampleExclusion::count())->toBe(1);

    $f['providers']->providers['himalayas']->candidates[1] = F::candidate('himalayas', 'gen-reposted', 'Generic Co', 'Senior Software Engineer', F::description(['React.', 'Python APIs.', 'A different body entirely.'], unique: 'reposted'));
    app(BuildResumeProfile::class)->run($f['profile']->refresh(), pilot: true, force: true);

    $latest = MarketSample::latest('id')->first();
    $again = $latest->postings()->where('company', 'Generic Co')->get();
    expect($latest->id)->not->toBe(1)
        ->and($again)->not->toBeEmpty()
        ->and($again->every(fn ($p) => $p->decision === 'user_excluded' && ! $p->included))->toBeTrue()
        ->and($latest->aggregate['postings'])->toBe(2);
});

it('restores a posting, which also lifts the exclusion for future rebuilds', function () {
    $f = reviewedBuild();
    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed('Generic Co')]));
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.sample.user_excluded.0.company', 'Generic Co')
        ->where('resume_profile.sample.exclusions', fn ($e) => collect($e)->firstWhere('reason', 'user_excluded')['label'] === 'Removed by you'));

    $this->post(route('resume-profiles.postings.restore', [$f['profile'], postingNamed('Generic Co')]))->assertRedirect();

    $sample = MarketSample::latest('id')->first();
    expect(postingNamed('Generic Co')->included)->toBeTrue()
        ->and(MarketSampleExclusion::count())->toBe(0)
        ->and($sample->aggregate['postings'])->toBe(3)
        ->and($sample->aggregate['capabilities']['kubernetes']['postings'])->toBe(2);
});

it('can be explored at any sample size, including one posting or none', function () {
    $f = reviewedBuild();
    foreach (['Generic Co', 'Cluster Co'] as $company) {
        $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed($company)]))->assertRedirect();
    }
    $this->get(route('resume-profiles.show', $f['profile']))->assertOk()->assertInertia(fn ($page) => $page->where('resume_profile.sample.size', 1)->has('resume_profile.market'));

    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], postingNamed('Fullstack Co')]))->assertRedirect();
    $this->get(route('resume-profiles.show', $f['profile']))->assertOk()->assertInertia(fn ($page) => $page
        ->where('resume_profile.sample.size', 0)->where('resume_profile.market', [])->where('resume_profile.interview.progress.open', 0));
});

it('refuses to change a posting outside the profile current sample', function () {
    $f = reviewedBuild();
    $old = postingNamed('Generic Co');
    app(BuildResumeProfile::class)->run($f['profile']->refresh(), pilot: true, fetch: false);

    $this->post(route('resume-profiles.postings.exclude', [$f['profile'], $old]))->assertNotFound();
});
