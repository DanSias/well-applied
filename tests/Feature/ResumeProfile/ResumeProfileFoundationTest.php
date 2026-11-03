<?php

use App\Enums\ResumeProfileStatus;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\ResumeProfile;
use App\Support\ResumeProfile\ProfileDefinitions;
use App\Support\ResumeProfile\ProfileInterview;
use Tests\Support\ResumeProfileFixtures as F;

it('creates one profile per definition per career profile, referencing facts by key and never copying them', function () {
    $f = F::career();

    $again = ProfileDefinitions::ensure($f['career'], 'full-stack-product');

    expect($again->id)->toBe($f['profile']->id)
        ->and(ResumeProfile::count())->toBe(1)
        ->and($again)->toMatchArray(['slug' => 'full-stack-product', 'name' => 'Full-Stack / Product Engineering', 'role_family' => 'full_stack_product', 'revision' => 1, 'evidence_revision' => 0])
        ->and($again->status)->toBe(ResumeProfileStatus::Draft)
        ->and($again->market_sample_id)->toBeNull()
        // There is nowhere on the profile to hold a copy of a fact.
        ->and(array_keys($again->getAttributes()))->not->toContain('statement')->not->toContain('facts');
});

it('is built for more categories without code changes: a second definition is just configuration', function () {
    $f = F::career();
    config(['resume_profiles.profiles.data-platform' => [...config('resume_profiles.profiles.full-stack-product'), 'name' => 'Data Platform', 'role_family' => 'data_platform', 'purpose' => 'x']]);

    $second = ProfileDefinitions::ensure($f['career'], 'data-platform');

    expect($second->id)->not->toBe($f['profile']->id)
        ->and($second->role_family)->toBe('data_platform')
        ->and(ProfileDefinitions::slugs())->toBe(['full-stack-product', 'data-platform'])
        ->and(fn () => ProfileDefinitions::ensure($f['career'], 'nope'))->toThrow(InvalidArgumentException::class);
});

it('applies per-profile search parameters over the configured search', function () {
    $f = F::career();
    $f['profile']->update(['search_parameters' => ['include_terms' => ['platform engineer'], 'remote_preference' => 'any']]);

    $criteria = ProfileDefinitions::criteria($f['profile']->refresh());

    expect($criteria->keywords)->toBe(['platform engineer'])->and($criteria->remotePreference)->toBe('any')
        ->and(ProfileDefinitions::searchProfile($f['profile'])->label)->toBe('Full-Stack / Product Engineering');
});

it('resolves an open question when the record starts to cover the capability, without touching answered ones', function () {
    $f = F::career();
    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Kubernetes in production.', 'Third-party API integration.']]));
    $interview = app(ProfileInterview::class);
    $interview->sync($f['profile']->refresh());
    $containers = ClarificationQuestion::where('topic_key', 'containers')->sole();
    $api = ClarificationQuestion::where('topic_key', 'api_integration')->sole();
    $api->update(['status' => 'answered']);

    CareerFact::factory()->create(['career_profile_id' => $f['career']->id, 'key' => 'k8s', 'statement' => 'Ran Docker and Kubernetes deployments for the merchant portal.', 'verification' => 'strongly_supported', 'visibility' => 'public', 'attributable_type' => 'role', 'attributable_id' => $f['role']->id]);
    CareerFact::factory()->create(['career_profile_id' => $f['career']->id, 'key' => 'api', 'statement' => 'Third-party API integration for payment vendors.', 'verification' => 'strongly_supported', 'visibility' => 'public', 'attributable_type' => 'role', 'attributable_id' => $f['role']->id]);
    $interview->sync($f['profile']->refresh());

    expect($containers->fresh()->status)->toBe('covered')->and($api->fresh()->status)->toBe('answered');
});

it('marks the interview complete when nothing is left to ask', function () {
    $f = F::career();
    F::sampleWith($f['profile'], array_fill(0, 3, ['required' => ['React experience.']]));

    $session = app(ProfileInterview::class)->sync($f['profile']->refresh());

    expect($session->questions()->count())->toBe(0)
        ->and($session->status->value)->toBe('completed')
        ->and($f['profile']->fresh()->status)->toBe(ResumeProfileStatus::Ready);
});

it('builds from the command using only stored postings, and reports the decisions', function () {
    $f = F::career();
    foreach (range(1, 4) as $i) {
        F::stored($f['career'], "Stored {$i}", "Senior Full Stack Engineer {$i}", F::description(['React and Node.js.', 'Kubernetes.'], unique: "cmd{$i}"));
    }

    $this->artisan('profile:build', ['--pilot' => true, '--no-fetch' => true])
        ->expectsOutputToContain('4 included of 4 considered')
        ->assertSuccessful();

    expect($f['profile']->fresh()->market_sample_id)->not->toBeNull();
});

it('lists the profiles', function () {
    F::career();

    $this->get(route('resume-profiles.index'))->assertInertia(fn ($page) => $page
        ->has('profiles', 1)
        ->where('profiles.0.slug', 'full-stack-product')
        ->where('profiles.0.name', 'Full-Stack / Product Engineering'));
});

it('refreshes unanswered questions from a newer sample and retires those the market no longer favours, leaving answered ones alone', function () {
    $f = F::career();
    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Kubernetes in production.', 'Third-party API integration.']]));
    $interview = app(ProfileInterview::class);
    $interview->sync($f['profile']->refresh());
    $api = ClarificationQuestion::where('topic_key', 'api_integration')->sole();
    $api->update(['status' => 'answered']);
    $oldText = ClarificationQuestion::where('topic_key', 'containers')->sole()->question_text;
    $position = ClarificationQuestion::where('topic_key', 'containers')->sole()->sort_order;

    // A new sample of 10 postings: Kubernetes now in half of them; API integration vanishes.
    F::sampleWith($f['profile'], [...array_fill(0, 5, ['required' => ['Kubernetes in production.']]), ...array_fill(0, 5, ['required' => ['Ruby on Rails.']])]);
    $interview->sync($f['profile']->refresh());

    $containers = ClarificationQuestion::where('topic_key', 'containers')->sole();
    expect($containers->question_text)->not->toBe($oldText)->toContain('5 of the 10')
        ->and($containers->sort_order)->toBe($position)
        ->and($api->fresh()->status)->toBe('answered')
        ->and(ClarificationQuestion::where('topic_key', 'ruby_on_rails')->exists())->toBeTrue();
});
