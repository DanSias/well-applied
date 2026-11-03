<?php

use App\Enums\ClarificationSessionStatus;
use App\Enums\Verification;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceProposal;
use App\Models\PresentationPreference;
use App\Models\Skill;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeProfile\RankDiscoveryTopics;
use Tests\Support\ResumeProfileFixtures as F;

/** Six postings with a deliberate market shape. */
function marketSpecs(): array
{
    $handlers = ['React experience.', 'Build webhook handlers with idempotent retry logic.', 'Kubernetes in production.'];

    return [
        ['required' => $handlers, 'preferred' => ['GraphQL.']],
        ['required' => $handlers, 'preferred' => ['GraphQL.']],
        ['required' => ['React experience.', 'Build webhook handlers.', 'Kubernetes.'], 'preferred' => ['Terraform.']],
        ['required' => ['React experience.', 'Third-party API integration.', 'Kubernetes.'], 'preferred' => ['GraphQL.']],
        ['required' => ['React experience.', 'Third-party API integration.'], 'duties' => ['Own features end-to-end.']],
        ['required' => ['Python and Django.', 'Ruby on Rails.'], 'duties' => ['Own features end-to-end.']],
    ];
}

function coverageFor(array $f): array
{
    return app(CapabilityCoverage::class)->compute($f['career']);
}

it('classifies directly supported, transferable, thinly documented, limitation and no evidence', function () {
    $f = F::career();
    $graphql = Skill::factory()->create(['career_profile_id' => $f['career']->id, 'name' => 'GraphQL']);
    $f['react']->skills()->attach($graphql);
    CareerFact::factory()->create(['career_profile_id' => $f['career']->id, 'key' => 'unconfirmed-terraform', 'statement' => 'Used Terraform for staging environments.', 'verification' => Verification::NeedsConfirmation, 'attributable_type' => 'role', 'attributable_id' => $f['role']->id]);
    CareerLimitation::create(['career_profile_id' => $f['career']->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    $c = coverageFor($f)['capabilities'];

    expect($c['react']['status'])->toBe(CapabilityCoverage::DIRECT)
        ->and($c['react']['facts'][0]['key'])->toBe($f['react']->key)
        ->and($c['typescript']['status'])->toBe(CapabilityCoverage::DIRECT)
        ->and($c['webhooks']['status'])->toBe(CapabilityCoverage::DIRECT)
        // Related, not stated: webhook integration supports "handler implementation" only as transferable.
        ->and($c['webhook_handlers']['status'])->toBe(CapabilityCoverage::TRANSFERABLE)
        ->and($c['retry_idempotency']['status'])->toBe(CapabilityCoverage::TRANSFERABLE)
        ->and($c['graphql']['status'])->toBe(CapabilityCoverage::UNDERDOCUMENTED)
        ->and($c['infrastructure_as_code']['status'])->toBe(CapabilityCoverage::UNDERDOCUMENTED)
        ->and($c['ruby_on_rails']['status'])->toBe(CapabilityCoverage::LIMITATION)
        ->and($c['api_integration']['status'])->toBe(CapabilityCoverage::NONE);
});

it('lets evidence for a child capability support its parent, but not the reverse', function () {
    $f = F::career();
    CareerFact::factory()->create(['career_profile_id' => $f['career']->id, 'key' => 'handlers', 'statement' => 'Built webhook handlers for payment events.', 'verification' => Verification::StronglySupported, 'attributable_type' => 'role', 'attributable_id' => $f['role']->id]);

    $c = coverageFor($f)['capabilities'];

    expect($c['webhook_handlers']['status'])->toBe(CapabilityCoverage::DIRECT)
        ->and($c['webhooks']['status'])->toBe(CapabilityCoverage::DIRECT);
});

it('never counts Private evidence, and never exposes its wording', function () {
    $f = F::career();

    $coverage = coverageFor($f);

    // The only Kubernetes evidence is a Private fact: it is not eligible, so the gap stays visible.
    expect($coverage['capabilities']['containers']['status'])->toBe(CapabilityCoverage::NONE)
        ->and(json_encode($coverage))->not->toContain('ZEBRA')->not->toContain('private-zebra');
});

it('ranks undocumented, prevalent, answerable capabilities first and explains each score', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], marketSpecs());

    $ranked = app(RankDiscoveryTopics::class)->rank($f['profile'], $sample->aggregate, coverageFor($f));
    $keys = array_column($ranked, 'key');

    // Documented capabilities are never asked about.
    expect($keys)->not->toContain('react')->not->toContain('webhooks')
        ->and($keys)->toContain('containers')->toContain('webhook_handlers')->toContain('api_integration')
        ->and(array_search('containers', $keys, true))->toBeLessThan(array_search('graphql', $keys, true))
        ->and($ranked[0]['breakdown'])->toHaveKeys(['importance', 'gap', 'answerability', 'usefulness', 'coverage'])
        ->and($ranked[0]['score'])->toBeGreaterThanOrEqual($ranked[1]['score']);

    $handlers = collect($ranked)->firstWhere('key', 'webhook_handlers');
    expect($handlers['status'])->toBe(CapabilityCoverage::TRANSFERABLE)
        // Required in 3 of 6 postings: market evidence is carried with the candidate.
        ->and($handlers['market']['required'])->toBe(3)
        ->and($handlers['market']['postings'])->toBe(3);
});

it('gives more weight to what is required and to what the user likely has, than to a rare preferred tool', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], marketSpecs());
    $ranked = collect(app(RankDiscoveryTopics::class)->rank($f['profile'], $sample->aggregate, coverageFor($f)))->keyBy('key');

    expect($ranked['containers']['score'])->toBeGreaterThan($ranked['graphql']['score'] ?? 0)
        ->and($ranked['webhook_handlers']['breakdown']['answerability'])->toBeGreaterThan($ranked['api_integration']['breakdown']['answerability']);
});

it('leaves out capabilities that are too rare in the sample', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], marketSpecs());

    $keys = array_column(app(RankDiscoveryTopics::class)->rank($f['profile'], $sample->aggregate, coverageFor($f)), 'key');

    // Terraform appears in one of six postings, as a preferred item: below the minimum posting count.
    expect($keys)->not->toContain('infrastructure_as_code');
});

it('suppresses limitations, approved topics, preferences and recently skipped questions, but not old skips', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], marketSpecs());
    $rank = fn () => array_column(app(RankDiscoveryTopics::class)->rank($f['profile'], $sample->aggregate, coverageFor($f)), 'key');
    $base = $rank();

    CareerLimitation::create(['career_profile_id' => $f['career']->id, 'topic_key' => 'containers', 'topic' => 'Containers', 'terms' => ['Docker'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);
    EvidenceProposal::create(['career_profile_id' => $f['career']->id, 'kind' => 'fact', 'status' => 'approved', 'topic_key' => 'api_integration', 'topic_label' => 'API', 'statement' => 'x', 'scopes' => ['integrated'], 'visibility' => 'public', 'verification' => 'strongly_supported', 'history' => []]);
    PresentationPreference::create(['career_profile_id' => $f['career']->id, 'topic_key' => 'end_to_end_ownership', 'topic' => 'Ownership', 'guidance' => 'Emphasize.', 'confirmed_at' => now()]);

    $session = ClarificationSession::create(['career_profile_id' => $f['career']->id, 'resume_profile_id' => $f['profile']->id, 'mode' => 'profile', 'status' => ClarificationSessionStatus::Pending]);
    $skipped = ClarificationQuestion::create(['clarification_session_id' => $session->id, 'topic_key' => 'webhook_handlers', 'topic_label' => 'Handlers', 'related_finding_ids' => [], 'question_text' => 'Q', 'score' => 0.5, 'score_breakdown' => [], 'context' => [], 'suggested_visibility' => 'restricted', 'status' => 'skipped']);

    $after = $rank();
    expect($base)->toContain('containers')->toContain('api_integration')->toContain('webhook_handlers')
        ->and($after)->not->toContain('containers')->not->toContain('api_integration')->not->toContain('end_to_end_ownership')->not->toContain('webhook_handlers');

    $skipped->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();
    expect($rank())->toContain('webhook_handlers');
});
