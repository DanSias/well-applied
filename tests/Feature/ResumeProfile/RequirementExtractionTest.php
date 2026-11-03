<?php

use App\Models\MarketSample;
use App\Support\ResumeProfile\AggregateMarketRequirements;
use App\Support\ResumeProfile\PostingText;
use App\Support\ResumeProfile\RequirementExtractor;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResumeProfileFixtures as F;

function extractFrom(string $html, string $title = 'Senior Full Stack Engineer'): array
{
    return app(RequirementExtractor::class)->extract($title, PostingText::clean($html));
}

it('classifies required, preferred, duty and mentioned from sections and cues', function () {
    $x = extractFrom(F::description(
        required: ['Strong experience with React.', 'Proficiency in PostgreSQL.'],
        preferred: ['Experience with GraphQL.', 'Terraform is a plus.'],
        duties: ['Design and run CI/CD pipelines.'],
    ));
    $c = $x['capabilities'];

    expect($c['react']['level'])->toBe('required')
        ->and($c['sql_databases']['level'])->toBe('required')
        ->and($c['graphql']['level'])->toBe('preferred')
        ->and($c['infrastructure_as_code']['level'])->toBe('preferred')
        ->and($c['ci_cd']['level'])->toBe('duty')
        ->and($c['react']['snippet'])->toContain('React');
});

it('does not count benefits, perks or about-us text', function () {
    $x = extractFrom(F::description(['Node.js experience.'], benefits: 'Our perks include Kubernetes training, Stripe stock options and Redis conferences.'));

    expect($x['capabilities'])->not->toHaveKey('kubernetes')->not->toHaveKey('payment_gateways')->not->toHaveKey('nosql_cache')->toHaveKey('nodejs');
});

it('keeps neighbouring integration capabilities separate instead of collapsing them into one "API" skill', function () {
    $general = extractFrom(F::description(['Integrate with third-party APIs from payment and CRM vendors.']))['capabilities'];
    $webhooks = extractFrom(F::description(['Configure webhooks with partners.']))['capabilities'];
    $handlers = extractFrom(F::description(['Build webhook handlers that process events.', 'Implement idempotent retry logic and exponential backoff.']))['capabilities'];
    $rest = extractFrom(F::description(['Design and build REST APIs for the product.']))['capabilities'];

    expect($general)->toHaveKey('api_integration')->not->toHaveKey('webhooks')->not->toHaveKey('webhook_handlers')
        ->and($webhooks)->toHaveKey('webhooks')->not->toHaveKey('webhook_handlers')->not->toHaveKey('api_integration')
        ->and($handlers)->toHaveKeys(['webhook_handlers', 'webhooks', 'retry_idempotency'])->not->toHaveKey('api_integration')
        ->and($rest)->toHaveKey('rest_api_design')->not->toHaveKey('api_integration');
});

it('does not read ordinary words as technologies', function () {
    $x = extractFrom(F::description(['Be able to react quickly to the rest of the team, go the extra mile and work in a rails-free zone.']));

    expect($x['capabilities'])->not->toHaveKey('react')->not->toHaveKey('rest_api_design')->not->toHaveKey('go_rust')->not->toHaveKey('ruby_on_rails');
});

it('reads the title, seniority, years and primary stack', function () {
    $x = extractFrom(F::description(['5+ years of professional experience building web applications.', 'Laravel, PHP and Laravel again.'], unique: 'laravel'), 'Staff Ruby on Rails Engineer');

    expect($x['capabilities']['ruby_on_rails']['level'])->toBe('required')
        ->and($x['seniority'])->toBe('staff_plus')
        ->and($x['years_min'])->toBe(5)
        ->and($x['stack'])->toBe('php_laravel');
    expect(extractFrom(F::description(['2 years experience.']), 'Junior Developer')['seniority'])->toBe('junior');
});

it('aggregates counts, shares, required versus preferred prevalence, seniority and supporting postings', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], [
        ['title' => 'Senior Full Stack Engineer', 'required' => ['React experience.', 'Build webhook handlers.'], 'preferred' => ['GraphQL.']],
        ['title' => 'Staff Full Stack Engineer', 'required' => ['React experience.'], 'preferred' => ['Terraform.']],
        ['title' => 'Full Stack Engineer', 'required' => ['Vue experience.'], 'preferred' => ['React is a plus.', 'GraphQL.']],
        ['title' => 'Senior Product Engineer', 'required' => ['Python and Django.'], 'duties' => ['Own features end-to-end.']],
    ]);
    $agg = $sample->aggregate;
    $react = $agg['capabilities']['react'];

    expect($agg['postings'])->toBe(4)
        ->and($react['postings'])->toBe(3)
        ->and($react['share'])->toBe(0.75)
        ->and($react['required'])->toBe(2)
        ->and($react['preferred'])->toBe(1)
        ->and($react['required_share'])->toBe(0.5)
        ->and($react['preferred_share'])->toBe(0.25)
        ->and($react['posting_ids'])->toHaveCount(3)
        ->and($react['examples'])->not->toBeEmpty()
        ->and($react['seniority'])->toMatchArray(['senior' => 1, 'staff_plus' => 1])
        ->and($agg['capabilities']['graphql']['preferred'])->toBe(2)
        ->and($agg['capabilities']['webhook_handlers']['postings'])->toBe(1)
        ->and($agg['capabilities']['end_to_end_ownership']['duty'])->toBe(1)
        ->and(array_key_first($agg['capabilities']))->toBe('react');
});

it('reads each unchanged posting once: nothing is extracted again, and an identical posting in a new sample reuses the cache', function () {
    Http::fake();
    $f = F::career();
    $sample = F::sampleWith($f['profile'], [['required' => ['React experience.']], ['required' => ['Node.js experience.']]]);

    $again = app(AggregateMarketRequirements::class)->run($sample);
    expect($again)->toMatchArray(['extracted' => 0, 'cached' => 2, 'postings' => 2]);

    // The same text in another sample is found by content hash and version.
    $second = MarketSample::create(['resume_profile_id' => $f['profile']->id, 'status' => 'ready', 'pilot' => true, 'target_size' => 1, 'extraction_version' => 'x']);
    $copy = $sample->postings()->first()->replicate(['extraction', 'extraction_version']);
    $copy->market_sample_id = $second->id;
    $copy->save();

    expect(app(AggregateMarketRequirements::class)->run($second))->toMatchArray(['extracted' => 0, 'cached' => 1]);
    // No model call exists anywhere in extraction.
    Http::assertNothingSent();
});

it('re-reads every posting exactly once when the extraction version changes', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], [['required' => ['React experience.']], ['required' => ['Node.js experience.']]]);

    config(['resume_profiles.extraction_version' => 'market-extract-v3']);

    expect(app(AggregateMarketRequirements::class)->run($sample))->toMatchArray(['extracted' => 2, 'cached' => 0])
        ->and(app(AggregateMarketRequirements::class)->run($sample))->toMatchArray(['extracted' => 0, 'cached' => 2]);
});
