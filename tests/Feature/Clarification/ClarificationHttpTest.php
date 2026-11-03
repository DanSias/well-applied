<?php

use App\Enums\GenerationType;
use App\Jobs\GenerateResumeVariantJob;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\GenerationAttempt;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ClarificationFixtures;

beforeEach(function () {
    Queue::fake();
    $this->s = ClarificationFixtures::scenario();
    app(TailoredResumePipeline::class)->start($this->s['posting']);
    $this->question = ClarificationQuestion::where('topic_key', 'webhooks')->firstOrFail();
});

function answerUrl(array $s, ClarificationQuestion $q): string
{
    return route('jobs.clarification.answer', [$s['posting'], $q]);
}

it('walks the whole path through the real endpoints: answer, review, approve, continue', function () {
    $s = $this->s;

    $this->post(answerUrl($s, $this->question), [
        'mode' => 'experience', 'scopes' => ['integrated', 'configured'], 'answer_text' => 'Worked with merchant integrations involving webhooks for retry schedules.',
    ])->assertRedirect();

    $proposal = EvidenceProposal::sole();
    expect(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0);

    $this->patch(route('evidence-proposals.update', $proposal), ['statement' => 'Worked with merchant integrations involving webhooks, including retry schedules.'])->assertRedirect();
    $this->post(route('evidence-proposals.approve', $proposal), ['visibility' => 'public'])->assertRedirect();

    $fact = CareerFact::where('key', 'like', '%user-confirmed')->sole();
    expect($fact->statement)->toBe('Worked with merchant integrations involving webhooks, including retry schedules.');

    $this->post(route('jobs.clarification.continue', $s['posting']))->assertRedirect(route('jobs.show', $s['posting']));

    // Evidence changed, so matching is refreshed before Selection.
    expect(GenerationAttempt::sole()->generation_type)->toBe(GenerationType::JobMatch);
});

it('shows the proposal for review on the job page after answering, and offers Generate Now', function () {
    $s = $this->s;
    $this->post(answerUrl($s, $this->question), ['mode' => 'experience', 'scopes' => ['configured'], 'answer_text' => 'Configured webhooks.']);

    $props = $this->get(route('jobs.show', $s['posting']))->viewData('page')['props']['tailored_resume'];

    expect($props['state'])->toBe('needs_clarification')
        ->and($props['clarification']['questions'][0]['proposal'])->toMatchArray(['status' => 'proposed', 'kind' => 'fact', 'visibility' => 'restricted', 'verification' => 'strongly_supported'])
        ->and($props['clarification']['attribution_options'])->not->toBeEmpty();
});

it('answers validation problems as errors and saves nothing', function () {
    $s = $this->s;

    $this->post(answerUrl($s, $this->question), ['mode' => 'experience', 'scopes' => [], 'answer_text' => 'Something.'])->assertSessionHasErrors('scopes');
    $this->post(answerUrl($s, $this->question), ['mode' => 'nonsense', 'answer_text' => 'x'])->assertSessionHasErrors('mode');

    expect(EvidenceProposal::count())->toBe(0);
});

it('requires an explicit visibility to approve and reports scope-wording problems', function () {
    $s = $this->s;
    $this->post(answerUrl($s, $this->question), ['mode' => 'experience', 'scopes' => ['configured'], 'answer_text' => 'Configured webhooks.']);
    $proposal = EvidenceProposal::sole();

    $this->post(route('evidence-proposals.approve', $proposal), [])->assertSessionHasErrors('visibility');
    $this->post(route('evidence-proposals.approve', $proposal), ['visibility' => 'public', 'statement' => 'Built webhook handlers.'])->assertSessionHasErrors('statement');

    expect(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0);
});

it('rejects and skips without saving anything', function () {
    $s = $this->s;
    $this->post(answerUrl($s, $this->question), ['mode' => 'experience', 'scopes' => ['configured'], 'answer_text' => 'Configured webhooks.']);

    $this->post(route('evidence-proposals.reject', EvidenceProposal::sole()))->assertRedirect();
    $billing = ClarificationQuestion::where('topic_key', 'recurring_billing')->firstOrFail();
    $this->post(route('jobs.clarification.skip', [$s['posting'], $billing]))->assertRedirect();

    expect(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0)
        ->and($billing->fresh()->status)->toBe('skipped');
});

it('Generate Now with nothing answered resumes the held run and adds no evidence', function () {
    $s = $this->s;
    $facts = CareerFact::count();

    $this->post(route('jobs.clarification.continue', $s['posting']))->assertRedirect();

    expect(GenerationAttempt::sole()->generation_type)->toBe(GenerationType::ResumeVariant)
        ->and(CareerFact::count())->toBe($facts);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);

    // Nothing left to continue.
    $this->post(route('jobs.clarification.continue', $s['posting']))->assertNotFound();
});

it('lets a limitation be revised, after which the topic can be claimed again', function () {
    $s = $this->s;
    $limitation = CareerLimitation::create(['career_profile_id' => $s['profile']->id, 'topic_key' => 'ruby_on_rails', 'topic' => 'Ruby on Rails', 'terms' => ['Rails'], 'status' => 'no_direct_experience', 'source' => 'user_confirmation', 'confirmed_at' => now()]);

    $this->delete(route('career-limitations.revoke', $limitation))->assertRedirect();

    expect($limitation->fresh()->revoked_at)->not->toBeNull()->and(CareerLimitation::active()->count())->toBe(0);
});

it('does not let a question be answered through a different job', function () {
    $other = ClarificationFixtures::scenario();

    $this->post(route('jobs.clarification.answer', [$other['posting'], $this->question]), ['mode' => 'preference', 'answer_text' => 'x'])->assertNotFound();
});
