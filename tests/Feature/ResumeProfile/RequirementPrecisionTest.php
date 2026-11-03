<?php

use App\Support\ResumeProfile\PostingText;
use App\Support\ResumeProfile\RequirementExtractor;
use Tests\Support\ResumeProfileFixtures as F;

function read(array $required, array $preferred = [], array $duties = [], string $title = 'Senior Full Stack Engineer'): array
{
    return app(RequirementExtractor::class)->extract($title, PostingText::clean(F::description($required, $preferred, $duties)))['capabilities'];
}

it('does not read scalability language as distributed systems', function () {
    $c = read(['Build scalable applications.', 'Write scalable, maintainable code.', 'Experience with scalable services.']);

    expect($c)->not->toHaveKey('distributed_systems')
        ->and($c['scalability_performance']['confidence'])->not->toBe('low');
    // The bare word "scalable" alone is only a hint.
    $weak = read(['We love scalable things.']);
    expect($weak['scalability_performance']['confidence'])->toBe('low');
});

it('counts distributed systems only for explicit distributed-systems language', function () {
    expect(read(['Experience designing distributed systems.']))->toHaveKey('distributed_systems')
        ->and(read(['Work with microservices.']))->toHaveKey('distributed_systems')
        ->and(read(['Operate a high-availability platform.']))->toHaveKey('distributed_systems')
        ->and(read(['Handle high-traffic workloads.'])['distributed_systems']['confidence'])->toBe('low');
});

it('does not turn general API integration into webhook handler development', function () {
    $c = read(['Integrate with third-party APIs and external APIs.', 'Build API integrations for partners.']);

    expect($c)->toHaveKey('api_integration')->not->toHaveKey('webhooks')->not->toHaveKey('webhook_handlers')->not->toHaveKey('retry_idempotency');
    // Even a weak "external services" is only a hint.
    expect(read(['Call external services.'])['api_integration']['confidence'])->toBe('low');
});

it('keeps Docker and Kubernetes apart', function () {
    $docker = read(['Experience with Docker for local development.']);
    $k8s = read(['Run workloads on Kubernetes and Helm.']);

    expect($docker)->toHaveKey('docker')->not->toHaveKey('kubernetes')
        ->and($k8s)->toHaveKey('kubernetes')->not->toHaveKey('docker')
        ->and(read(['We containerize our services.'])['docker']['confidence'])->toBe('low');
});

it('does not read broad engineering responsibility as owning the architecture', function () {
    $broad = read(['Take responsibility for the engineering of features.', 'Own features from idea to production.', 'Be accountable for quality across the stack.']);

    expect($broad)->not->toHaveKey('architecture_ownership')->not->toHaveKey('system_design')
        ->and($broad)->toHaveKey('end_to_end_ownership');

    expect(read(['Own the architecture of our platform.']))->toHaveKey('architecture_ownership')
        ->and(read(['Set the technical direction for the team.']))->toHaveKey('architecture_ownership')
        ->and(read(['Write design documents for large changes.']))->toHaveKey('system_design')->not->toHaveKey('architecture_ownership');
    // "architecture" alone is a hint, not a system-design requirement.
    expect(read(['Understand our architecture.'])['system_design']['confidence'])->toBe('low');
});

it('records the supporting phrase, level, confidence and source for each classified capability', function () {
    $c = read(['Strong experience with Kubernetes.'], ['GraphQL is a plus.']);

    expect($c['kubernetes'])->toMatchArray(['level' => 'required', 'confidence' => 'high', 'matched' => 'Kubernetes'])
        ->and($c['kubernetes']['snippet'])->toContain('Strong experience with Kubernetes')
        ->and($c['graphql'])->toMatchArray(['level' => 'preferred', 'confidence' => 'medium', 'matched' => 'GraphQL']);
});

it('does not overstate prevalence from weak matches: they are kept as evidence but never counted', function () {
    $f = F::career();
    $sample = F::sampleWith($f['profile'], [
        ['required' => ['Build scalable systems for customers.', 'Write clean code with best practices.']],
        ['required' => ['We value scalable thinking.', 'Monitoring of our product.']],
        ['required' => ['Design distributed systems.', 'Own the architecture of our platform.']],
        ['required' => ['Work with designers and product managers.']],
    ]);
    $caps = $sample->aggregate['capabilities'];

    // Only one posting says "distributed systems"; none of the "scalable" lines inflate it.
    expect($caps['distributed_systems']['postings'])->toBe(1)
        ->and($caps['distributed_systems']['share'])->toBe(0.25)
        ->and($caps['scalability_performance']['postings'])->toBe(1)
        ->and($caps['scalability_performance']['weak_postings'])->toBe(1)
        ->and($caps['code_review']['postings'] ?? 0)->toBe(0)
        ->and($caps['code_review']['weak_postings'])->toBe(1)
        ->and($caps['observability']['postings'] ?? 0)->toBe(0)
        ->and($caps['cross_functional']['postings'] ?? 0)->toBe(0);

    $evidence = collect($caps['distributed_systems']['evidence']);
    expect($evidence)->toHaveCount(1)
        ->and($evidence[0])->toMatchArray(['level' => 'required', 'confidence' => 'high'])
        ->and($evidence[0]['posting_id'])->toBeInt()
        ->and($evidence[0]['snippet'])->toContain('distributed systems');
});

it('shows only counted capabilities, with their evidence, on the page', function () {
    $f = F::career();
    F::sampleWith($f['profile'], [['required' => ['Build scalable systems.']], ['required' => ['Distributed systems experience.']]]);

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.market', function ($groups) {
            $all = collect($groups)->flatMap(fn ($g) => $g['capabilities'])->keyBy('key');

            return $all->has('distributed_systems') && $all['distributed_systems']['evidence'][0]['matched'] !== null && $all['distributed_systems']['postings'] === 1;
        }));
});
