<?php

use App\Jobs\BuildResumeProfileJob;
use App\Jobs\EnrichResumeProfileQuestionsJob;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Support\ResumeProfile\BuildResumeProfile;
use App\Support\ResumeProfile\ClaimSeparationHint;
use App\Support\ResumeProfile\ProfileInterview;
use App\Support\ResumeProfile\QuestionEnrichment;
use App\Support\ResumeProfile\QuestionWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeQuestionWriter;
use Tests\Support\ResumeProfileFixtures as F;

function interviewReady(): array
{
    config(['services.ollama.base_url' => 'http://ollama.test']);
    $f = F::career();
    F::sampleWith($f['profile'], array_fill(0, 4, ['required' => ['Strong Kubernetes experience.', 'Strong REST API design.', 'Terraform.']]));
    app(ProfileInterview::class)->sync($f['profile']->refresh());

    return $f;
}

it('offers "Improve interview questions" without having called any model', function () {
    $f = interviewReady();
    $writer = new FakeQuestionWriter;
    app()->instance(QuestionWriter::class, $writer);

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.enrichment.current', 0)
        ->where('resume_profile.interview.enrichment.pending', fn ($n) => $n > 0)
        ->where('resume_profile.interview.enrichment.available', true)
        ->where('resume_profile.interview.enrichment.running', false)
        ->where('resume_profile.interview.questions.0.enriched', false));

    expect($writer->calls)->toBe(0);
});

it('queues one enrichment only when the user asks, and never twice at once', function () {
    Queue::fake();
    $f = interviewReady();

    $this->post(route('resume-profiles.improve-questions', $f['profile']))->assertRedirect();
    $this->post(route('resume-profiles.improve-questions', $f['profile']))->assertRedirect();

    Queue::assertPushed(EnrichResumeProfileQuestionsJob::class, 1);
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page->where('resume_profile.interview.enrichment.running', true));
});

it('a build never starts enrichment on its own', function () {
    Queue::fake();
    $f = interviewReady();
    $writer = new FakeQuestionWriter;
    app()->instance(QuestionWriter::class, $writer);

    $this->post(route('resume-profiles.build', $f['profile']));
    (new BuildResumeProfileJob($f['profile']->id))->handle(app(BuildResumeProfile::class));

    expect($writer->calls)->toBe(0);
    Queue::assertNotPushed(EnrichResumeProfileQuestionsJob::class);
});

it('runs the action through the existing enrichment: shows improved wording, keeps the original, and releases the lock', function () {
    $f = interviewReady();
    $writer = new FakeQuestionWriter;
    app()->instance(QuestionWriter::class, $writer);
    Cache::put(EnrichResumeProfileQuestionsJob::lockKey($f['profile']->id), true, 60);

    (new EnrichResumeProfileQuestionsJob($f['profile']->id))->handle(app(QuestionEnrichment::class));

    expect($writer->calls)->toBe(1)->and(Cache::has(EnrichResumeProfileQuestionsJob::lockKey($f['profile']->id)))->toBeFalse();
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.enrichment.pending', 0)
        ->where('resume_profile.interview.enrichment.model', 'fake-model')
        ->where('resume_profile.interview.questions.0.enriched', true)
        ->where('resume_profile.interview.questions.0.text', fn ($t) => str_contains($t, 'most substantial'))
        ->where('resume_profile.interview.questions.0.original_text', fn ($t) => str_contains($t, 'postings')));

    // Nothing left to improve: the action does nothing.
    Queue::fake();
    $this->post(route('resume-profiles.improve-questions', $f['profile']));
    Queue::assertNothingPushed();
});

it('shows each answer with the original text, proposed fact, scope, attribution, visibility and approval status', function () {
    $f = interviewReady();
    $q = ClarificationQuestion::where('topic_key', 'kubernetes')->firstOrFail();
    $this->post(route('resume-profiles.answer', [$f['profile'], $q]), ['mode' => 'experience', 'scopes' => ['configured', 'troubleshot'], 'answer_text' => 'At RocketGate I configured the staging cluster and fixed its deploy failures.']);

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->has('resume_profile.interview.answers', 1)
        ->where('resume_profile.interview.answers.0.answer.text', 'At RocketGate I configured the staging cluster and fixed its deploy failures.')
        ->where('resume_profile.interview.answers.0.answer.scopes', ['Configured it', 'Troubleshot it'])
        ->where('resume_profile.interview.answers.0.proposal.status', 'proposed')
        ->where('resume_profile.interview.answers.0.proposal.statement', 'At RocketGate I configured the staging cluster and fixed its deploy failures.')
        ->where('resume_profile.interview.answers.0.proposal.attribution', 'RocketGate: Developer Support Engineer')
        ->where('resume_profile.interview.answers.0.proposal.visibility', 'restricted')
        ->where('resume_profile.interview.answers.0.separate_claims.likely', false));

    $this->post(route('evidence-proposals.approve', EvidenceProposal::sole()), ['visibility' => 'restricted']);
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.proposal.status', 'approved')
        ->where('resume_profile.interview.answers.0.proposal.fact_key', fn ($k) => str_ends_with($k, 'kubernetes-user-confirmed')));
});

it('flags an answer that probably holds several claims, without splitting or approving anything', function () {
    $names = ['RocketGate', 'Pearson', 'Workflow Intelligence'];
    $multi = ClaimSeparationHint::check('At RocketGate I ran the webhook retry setup. At Pearson I built the reporting dashboard; I also mentored two analysts. Workflow Intelligence used the same patterns.', $names, ['implemented', 'advised', 'supported']);
    $single = ClaimSeparationHint::check('At RocketGate I configured the staging cluster.', $names, ['configured']);

    expect($multi['likely'])->toBeTrue()
        ->and(implode(' ', $multi['reasons']))->toContain('sentences')->toContain('several of your employers or projects')->toContain('also')
        ->and($single)->toBe(['likely' => false, 'reasons' => []]);
});

it('shows the hint on the page for a long answer and leaves the proposal untouched', function () {
    $f = interviewReady();
    $q = ClarificationQuestion::where('topic_key', 'kubernetes')->firstOrFail();
    $this->post(route('resume-profiles.answer', [$f['profile'], $q]), ['mode' => 'experience', 'scopes' => ['configured'], 'answer_text' => 'I configured the staging cluster. I also wrote the deploy runbook. Later I trained the support team on it.']);

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.answers.0.separate_claims.likely', true)
        ->where('resume_profile.interview.answers.0.proposal.status', 'proposed'));
    expect(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0);
});
