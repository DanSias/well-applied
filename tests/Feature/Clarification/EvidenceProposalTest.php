<?php

use App\Enums\EvidenceSource;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceProposal;
use App\Models\PresentationPreference;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClarificationFixtures;

const WEBHOOK_ANSWER = 'At RocketGate, worked with merchant integrations involving webhooks for retry schedules, account management, and related functionality.';

/**
 * @return array{0: array<string, mixed>, 1: ClarificationQuestion}
 */
function pendingWebhookQuestion(): array
{
    $s = ClarificationFixtures::scenario();
    app(ClarificationGate::class)->pauseIfNeeded($s['posting'], $s['analysis'], $s['match'], 'run-1');

    return [$s, ClarificationQuestion::query()->where('topic_key', 'webhooks')->latest('id')->firstOrFail()];
}

function answerWebhooks(ClarificationQuestion $question, array $scopes = ['integrated', 'configured', 'advised'], string $text = WEBHOOK_ANSWER): EvidenceProposal
{
    return app(SubmitClarificationAnswer::class)->submit($question, 'experience', $scopes, $text);
}

function approveWithVisibility(EvidenceProposal $proposal, string $visibility = 'public', array $extra = [], array $options = []): EvidenceProposal
{
    return app(ReviewEvidenceProposal::class)->approve($proposal, ['visibility' => $visibility, ...$extra], $options);
}

it('stores the answer verbatim and proposes a fact without creating one', function () {
    [$s, $question] = pendingWebhookQuestion();
    $facts = CareerFact::count();

    $proposal = answerWebhooks($question);

    $answer = $question->answers()->sole();
    expect($answer->answer_text)->toBe(WEBHOOK_ANSWER)
        ->and($answer->scopes)->toBe(['integrated', 'configured', 'advised'])
        ->and($proposal->kind)->toBe(ProposalKind::Fact)
        ->and($proposal->status)->toBe(ProposalStatus::Proposed)
        ->and($proposal->topic_key)->toBe('webhooks')
        ->and($proposal->job_posting_id)->toBe($s['posting']->id)
        ->and($proposal->job_analysis_finding_id)->toBe($s['webhook']->id)
        ->and($proposal->attributable_type)->toBe('role')
        ->and($proposal->attributable_id)->toBe($s['role']->id)
        ->and($proposal->visibility)->toBe(Visibility::Restricted)
        ->and($proposal->verification)->toBe(Verification::StronglySupported)
        ->and($proposal->history[0]['action'])->toBe('proposed')
        ->and(CareerFact::count())->toBe($facts)
        ->and($question->fresh()->status)->toBe('answered');
});

it('attributes to the employer the answer names, overriding the suggestion', function () {
    [$s, $question] = pendingWebhookQuestion();

    $proposal = answerWebhooks($question, text: 'At Pearson, I configured webhooks for reporting.');

    expect($proposal->attributable_id)->toBe($s['olderRole']->id);
});

it('does not borrow the suggested role\'s visibility when the answer names a different employer', function () {
    [$s, $question] = pendingWebhookQuestion();
    $question->update(['suggested_attributable_id' => $s['olderRole']->id, 'suggested_visibility' => 'public']);

    $same = answerWebhooks($question, text: 'Configured webhooks for reporting.');
    $named = answerWebhooks($question, text: 'At RocketGate, I configured webhooks for merchants.');

    expect($same->visibility)->toBe(Visibility::Public)
        ->and($named->attributable_id)->toBe($s['role']->id)
        ->and($named->visibility)->toBe(Visibility::Restricted);
});

it('drops a leading "At <employer>," from the draft statement but keeps the answer verbatim', function () {
    [, $question] = pendingWebhookQuestion();

    $proposal = answerWebhooks($question);

    expect($proposal->statement)->toStartWith('Worked with merchant integrations involving webhooks')
        ->and($proposal->answer->answer_text)->toBe(WEBHOOK_ANSWER);
});

it('requires a scope and an answer for experience, and never invents either', function () {
    [, $question] = pendingWebhookQuestion();

    expect(fn () => answerWebhooks($question, []))->toThrow(ValidationException::class)
        ->and(fn () => answerWebhooks($question, ['configured'], '   '))->toThrow(ValidationException::class)
        ->and(EvidenceProposal::count())->toBe(0);
});

it('approves only with an explicit visibility and records the answer as user-confirmed provenance', function () {
    [$s, $question] = pendingWebhookQuestion();
    $proposal = answerWebhooks($question);
    $revision = $s['profile']->fresh()->evidence_revision;

    expect(fn () => app(ReviewEvidenceProposal::class)->approve($proposal, []))->toThrow(ValidationException::class)
        ->and(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0);

    $proposal = approveWithVisibility($proposal, 'public', [
        'statement' => 'Worked with merchant integrations involving webhooks, including retry schedules and account management.',
    ]);

    $fact = $proposal->careerFact;
    $evidence = $fact->evidence()->sole();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($fact->visibility)->toBe(Visibility::Public)
        ->and($fact->verification)->toBe(Verification::StronglySupported)
        ->and($fact->attributable_type)->toBe('role')
        ->and($fact->attributable_id)->toBe($s['role']->id)
        ->and($fact->key)->toEndWith('-webhooks-user-confirmed')
        ->and($fact->notes)->toContain('Integrated it')->toContain('Configured it')->toContain('Advised others on it')
        ->and($evidence->source)->toBe(EvidenceSource::UserConfirmed)
        ->and($evidence->quoted_text)->toBe(WEBHOOK_ANSWER)
        ->and($evidence->confirmed_at)->not->toBeNull()
        ->and($evidence->metadata['topic_key'])->toBe('webhooks')
        ->and(collect($proposal->history)->pluck('action')->all())->toBe(['proposed', 'edited', 'approved'])
        ->and($s['profile']->fresh()->evidence_revision)->toBe($revision + 1);
});

it('rejecting saves nothing, leaves the corpus revision alone, and quiets the topic', function () {
    [$s, $question] = pendingWebhookQuestion();
    $proposal = answerWebhooks($question);
    $revision = $s['profile']->fresh()->evidence_revision;

    app(ReviewEvidenceProposal::class)->reject($proposal);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected)
        ->and(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0)
        ->and($s['profile']->fresh()->evidence_revision)->toBe($revision)
        ->and($question->fresh()->status)->toBe('skipped')
        ->and(fn () => approveWithVisibility($proposal->fresh()))->toThrow(ValidationException::class);
});

it('cannot make configuring webhooks into building webhook handlers', function () {
    [, $question] = pendingWebhookQuestion();
    $proposal = answerWebhooks($question, ['configured', 'advised']);

    foreach (['Built webhook handlers for merchant retry schedules.', 'Implemented the webhook integration for merchants.', 'Led the merchant webhook program.'] as $statement) {
        expect(fn () => approveWithVisibility($proposal, 'public', ['statement' => $statement]))->toThrow(ValidationException::class);
    }

    expect(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0);
});

it('allows implementation wording when the user selected that scope', function () {
    [, $question] = pendingWebhookQuestion();
    $proposal = answerWebhooks($question, ['implemented'], 'I built the webhook handlers for our retry schedule.');

    expect(approveWithVisibility($proposal, 'public', ['statement' => 'Built webhook handlers for a retry schedule.'])->status)->toBe(ProposalStatus::Approved);
});

it('allows a verb the user used in their own words, even without the implemented scope', function () {
    [, $question] = pendingWebhookQuestion();
    $own = answerWebhooks($question, ['configured'], 'I built out the webhook retry configuration for merchants.');

    expect(approveWithVisibility($own, 'public', ['statement' => 'Built out the webhook retry configuration for merchants.'])->status)->toBe(ProposalStatus::Approved);
});

it('treats a self-reported figure as strongly supported unless the user explicitly substantiates it', function () {
    [, $question] = pendingWebhookQuestion();
    $proposal = answerWebhooks($question, ['configured'], 'I configured webhooks for 40 merchants.');

    expect(fn () => approveWithVisibility($proposal, 'public', ['verification' => 'verified']))->toThrow(ValidationException::class);

    $approved = approveWithVisibility($proposal, 'public', ['verification' => 'verified'], ['acknowledge_metric' => true]);
    expect($approved->careerFact->verification)->toBe(Verification::Verified);
});

it('refuses duplicates and requires an explicit replacement for the same topic, without adding a second fact', function () {
    [$s, $question] = pendingWebhookQuestion();
    $first = approveWithVisibility(answerWebhooks($question), 'public');
    $count = CareerFact::count();

    $again = answerWebhooks($question);
    expect(fn () => approveWithVisibility($again, 'public'))->toThrow(ValidationException::class, 'already recorded');

    $revised = answerWebhooks($question, ['configured'], 'Configured webhook retry schedules for merchants.');
    expect(fn () => approveWithVisibility($revised, 'public'))->toThrow(ValidationException::class, 'already approved');

    $replaced = approveWithVisibility($revised, 'restricted', [], ['supersede' => true]);

    expect(CareerFact::count())->toBe($count)
        ->and($replaced->career_fact_id)->toBe($first->career_fact_id)
        ->and($first->careerFact->fresh()->statement)->toBe('Configured webhook retry schedules for merchants.')
        ->and($first->careerFact->fresh()->visibility)->toBe(Visibility::Restricted)
        ->and($first->careerFact->evidence()->count())->toBe(2)
        ->and($first->fresh()->status)->toBe(ProposalStatus::Superseded);
});

it('keeps an approved Private fact out of every resume candidate payload', function () {
    [$s, $question] = pendingWebhookQuestion();
    $fact = approveWithVisibility(answerWebhooks($question), 'private')->careerFact;

    $keys = collect(app(ResumeCandidatePayloadBuilder::class)->build($s['match'])['career_facts'])->pluck('key')->all();

    expect($fact->visibility)->toBe(Visibility::Private)->and($keys)->not->toContain($fact->key);
});

it('makes an approved Public or Restricted fact available to Selection, attributed to its role', function () {
    [$s, $question] = pendingWebhookQuestion();
    $fact = approveWithVisibility(answerWebhooks($question), 'restricted')->careerFact;

    $payload = collect(app(ResumeCandidatePayloadBuilder::class)->build($s['match'])['career_facts'])->firstWhere('key', $fact->key);

    expect($payload)->not->toBeNull()
        ->and($payload['visibility'])->toBe('restricted')
        ->and($payload['attribution']['role_id'])->toBe($s['role']->id);
});

it('turns "no experience" into a limitation, never a fact, and blocks the claim', function () {
    [$s] = pendingWebhookQuestion();
    app(ClarificationGate::class)->deepen(ClarificationSession::sole());
    $rails = ClarificationQuestion::where('topic_key', 'ruby_on_rails')->firstOrFail();
    $facts = CareerFact::count();

    $proposal = app(SubmitClarificationAnswer::class)->submit($rails, 'no_experience', [], '');
    expect($proposal->kind)->toBe(ProposalKind::Limitation)->and($proposal->statement)->toBe('No direct experience with Ruby on Rails.');

    $approved = app(ReviewEvidenceProposal::class)->approve($proposal, []);

    $limitation = CareerLimitation::sole();
    expect($limitation->topic_key)->toBe('ruby_on_rails')
        ->and($limitation->status)->toBe('no_direct_experience')
        ->and($limitation->source)->toBe('user_confirmation')
        ->and($limitation->confirmed_at)->not->toBeNull()
        ->and($limitation->terms)->toContain('Rails')
        ->and($limitation->revoked_at)->toBeNull()
        ->and($approved->career_limitation_id)->toBe($limitation->id)
        ->and(CareerFact::count())->toBe($facts);
});

it('rejects an experience fact on a topic with an active limitation until the user revokes it', function () {
    [$s, $question] = pendingWebhookQuestion();
    $limit = app(SubmitClarificationAnswer::class)->submit($question, 'no_experience', [], '');
    app(ReviewEvidenceProposal::class)->approve($limit, []);

    $claim = answerWebhooks($question);
    expect(fn () => approveWithVisibility($claim))->toThrow(ValidationException::class, 'previously confirmed');

    $approved = approveWithVisibility($claim, 'public', [], ['revoke_limitation' => true]);

    expect($approved->status)->toBe(ProposalStatus::Approved)->and(CareerLimitation::sole()->revoked_at)->not->toBeNull();
});

it('refuses a limitation that contradicts approved evidence', function () {
    [, $question] = pendingWebhookQuestion();
    approveWithVisibility(answerWebhooks($question));

    $limit = app(SubmitClarificationAnswer::class)->submit($question, 'no_experience', [], '');

    expect(fn () => app(ReviewEvidenceProposal::class)->approve($limit, []))->toThrow(ValidationException::class, 'contradicts');
});

it('does not store a duplicate limitation for the same topic', function () {
    [, $question] = pendingWebhookQuestion();
    $a = app(SubmitClarificationAnswer::class)->submit($question, 'no_experience', [], '');
    app(ReviewEvidenceProposal::class)->approve($a, []);
    $b = app(SubmitClarificationAnswer::class)->submit($question, 'no_experience', [], 'Still none.');
    app(ReviewEvidenceProposal::class)->approve($b, []);

    expect(CareerLimitation::count())->toBe(1)->and($b->fresh()->career_limitation_id)->toBe(CareerLimitation::first()->id);
});

it('stores a presentation preference as guidance, not as a career fact, and leaves the corpus untouched', function () {
    [$s, $question] = pendingWebhookQuestion();
    $facts = CareerFact::count();
    $revision = $s['profile']->fresh()->evidence_revision;

    $proposal = app(SubmitClarificationAnswer::class)->submit($question, 'preference', [], 'Marketing and conversion experience may be emphasized where relevant.');
    app(ReviewEvidenceProposal::class)->approve($proposal, []);

    $preference = PresentationPreference::sole();
    expect($proposal->kind)->toBe(ProposalKind::Preference)
        ->and($preference->guidance)->toBe('Marketing and conversion experience may be emphasized where relevant.')
        ->and(CareerFact::count())->toBe($facts)
        ->and($s['profile']->fresh()->evidence_revision)->toBe($revision);
});

it('supersedes an undecided proposal when the answer is revised, and keeps both answers', function () {
    [, $question] = pendingWebhookQuestion();
    $first = answerWebhooks($question, ['configured'], 'Configured webhooks.');
    $second = answerWebhooks($question, ['configured'], 'Configured webhooks for retry schedules.');

    expect($first->fresh()->status)->toBe(ProposalStatus::Superseded)
        ->and($second->fresh()->status)->toBe(ProposalStatus::Proposed)
        ->and($question->answers()->count())->toBe(2)
        ->and($question->answers()->first()->answer_text)->toBe('Configured webhooks.');
});
