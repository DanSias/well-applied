<?php

use App\Models\ClarificationQuestion;
use App\Support\Clarification\EvidenceRevision;
use App\Support\ResumeProfile\BuildResumeProfile;
use App\Support\ResumeProfile\ProfileInterview;
use App\Support\ResumeProfile\QuestionEnrichment;
use App\Support\ResumeProfile\QuestionWriter;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuestionWriter;
use Tests\Support\ResumeProfileFixtures as F;

function enrichable(?FakeQuestionWriter $writer = null): array
{
    $f = F::career();
    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Strong Kubernetes experience.', 'Strong REST API design.', 'Terraform.']]));
    app(ProfileInterview::class)->sync($f['profile']->refresh());
    $writer ??= new FakeQuestionWriter;
    app()->instance(QuestionWriter::class, $writer);

    return [...$f, 'writer' => $writer, 'enrichment' => app(QuestionEnrichment::class)];
}

it('phrases every open question in a single batched call with a bounded schema', function () {
    $e = enrichable();
    $open = ClarificationQuestion::where('status', 'open')->count();

    $stats = $e['enrichment']->enrich($e['profile']->refresh());

    expect($e['writer']->calls)->toBe(1)
        ->and($stats)->toMatchArray(['attempted' => $open, 'enriched' => $open, 'rejected' => 0, 'failed' => false, 'model' => 'fake-model'])
        ->and($stats['usage'])->toMatchArray(['input_tokens' => 900, 'output_tokens' => 300])
        ->and($stats['latency_ms'])->toBeInt()
        ->and($e['writer']->lastSchema['properties']['questions']['minItems'])->toBe($open)
        ->and($e['writer']->lastSchema['additionalProperties'])->toBeFalse()
        ->and(ClarificationQuestion::first()->context['enrichment'])->toHaveKeys(['text', 'fingerprint', 'model', 'prompt_version', 'latency_ms', 'batch_usage']);
});

it('shows the phrased text while it is current and never calls a model on a page load', function () {
    Http::fake();
    $e = enrichable();
    $e['enrichment']->enrich($e['profile']->refresh());
    $calls = $e['writer']->calls;

    $this->get(route('resume-profiles.show', $e['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.questions.0.enriched', true)
        ->where('resume_profile.interview.questions.0.text', fn ($t) => str_contains($t, 'most substantial')));

    expect($e['writer']->calls)->toBe($calls);
    Http::assertNothingSent();
});

it('caches by evidence revision and sample: nothing is re-asked until one of them changes', function () {
    $e = enrichable();
    $e['enrichment']->enrich($e['profile']->refresh());

    $again = $e['enrichment']->enrich($e['profile']->refresh());
    expect($e['writer']->calls)->toBe(1)->and($again['cached'])->toBeGreaterThan(0)->and($again['attempted'])->toBe(0);

    // New approved evidence changes the revision: the cached wording is no longer used.
    EvidenceRevision::bump($e['career']);
    $e['profile']->update(['evidence_revision' => EvidenceRevision::current($e['career'])]);
    $question = ClarificationQuestion::where('status', 'open')->first();
    expect($e['enrichment']->display($e['profile']->refresh(), $question))->toBe($question->question_text);

    $e['enrichment']->enrich($e['profile']->refresh());
    expect($e['writer']->calls)->toBe(2);
});

it('falls back to the deterministic wording when the call fails', function () {
    $e = enrichable(new FakeQuestionWriter(fail: true));
    $question = ClarificationQuestion::where('status', 'open')->first();

    $stats = $e['enrichment']->enrich($e['profile']->refresh());

    expect($stats['failed'])->toBeTrue()->and($stats['enriched'])->toBe(0)
        ->and($e['enrichment']->display($e['profile']->refresh(), $question))->toBe($question->question_text);
    $this->get(route('resume-profiles.show', $e['profile']))->assertOk();
});

it('rejects an unusable phrasing per question and keeps the deterministic text for it', function () {
    $e = enrichable();
    $ids = ClarificationQuestion::where('status', 'open')->orderBy('id')->pluck('id', 'topic_key');
    $k8s = $ids['kubernetes'];
    $writer = new FakeQuestionWriter([
        $k8s => 'You built the Kubernetes platform at RocketGate for 99 clusters, which of them was biggest?',
    ]);
    app()->instance(QuestionWriter::class, $writer);

    $stats = app(QuestionEnrichment::class)->enrich($e['profile']->refresh());

    expect($stats['rejected'])->toBe(1)
        ->and(ClarificationQuestion::find($k8s)->context)->not->toHaveKey('enrichment')
        ->and(ClarificationQuestion::where('status', 'open')->where('id', '!=', $k8s)->get()->every(fn ($q) => isset($q->context['enrichment'])))->toBeTrue();
});

it('validates the phrasing: a question, on topic, no invented numbers, no asserted experience, no yes/no for deepening', function () {
    $e = enrichable();
    $q = ClarificationQuestion::where('topic_key', 'kubernetes')->first();
    $check = fn (string $text, $question = null) => (new ReflectionMethod(QuestionEnrichment::class, 'problem'))->invoke($e['enrichment'], $text, $question ?? $q);

    expect($check('Have you run Kubernetes in production, and what did you do there?'))->toBeNull()
        ->and($check('Tell me about the Kubernetes work you have done across your projects and roles.'))->toBe('not a question')
        ->and($check('Which of the 47 Kubernetes clusters did you manage?'))->toBe('invented number')
        ->and($check('You led the Kubernetes migration, so which cluster mattered most to you today?'))->toBe('asserts experience')
        ->and($check('Which database engines have you used across your applications and why did you choose them?'))->toBe('off topic')
        ->and($check('Short?'))->toBe('length');

    $deep = ClarificationQuestion::where('topic_key', 'kubernetes')->first();
    $deep->update(['context' => [...$deep->context, 'kind' => 'deepening']]);
    expect($check('Have you used Kubernetes on a project, and what did you personally do there?', $deep->fresh()))->toBe('yes/no question for a deepening topic');
});

it('runs inside the build only when enabled, and the default build makes no model call', function () {
    $f = F::career();
    $writer = new FakeQuestionWriter;
    app()->instance(QuestionWriter::class, $writer);
    F::providers(['himalayas' => array_map(fn (int $i) => F::candidate('himalayas', "e{$i}", "Co {$i}", "Senior Full Stack Engineer {$i}", F::description(['React and Node.js.', 'Strong Kubernetes experience.'], unique: "enr{$i}")), range(1, 4)), 'jobicy' => []]);

    $plain = app(BuildResumeProfile::class)->run($f['profile'], pilot: true);
    expect($plain['enrichment'])->toBeNull()->and($writer->calls)->toBe(0);

    $enriched = app(BuildResumeProfile::class)->run($f['profile']->refresh(), pilot: true, fetch: false, enrich: true);
    expect($enriched['enrichment']['enriched'])->toBeGreaterThan(0)->and($writer->calls)->toBe(1);

    config(['resume_profiles.enrichment.enabled' => true]);
    app(BuildResumeProfile::class)->run($f['profile']->refresh(), pilot: true, fetch: false);
    expect($writer->calls)->toBeGreaterThanOrEqual(1);
});
