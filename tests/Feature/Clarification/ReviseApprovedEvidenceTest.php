<?php

use App\Enums\ProposalStatus;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Support\CareerData\ApprovedEvidenceFile;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\ReviseApprovedEvidence;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClarificationFixtures;

const REVISION_ANSWER = 'At RocketGate, worked with merchant integrations involving webhooks for retry schedules, account management, and related functionality.';

/**
 * An approved webhook fact on the RocketGate role, restricted, scopes integrated + supported.
 *
 * @return array{0: array<string, mixed>, 1: CareerFact, 2: EvidenceProposal}
 */
function approvedWebhookFact(): array
{
    $s = ClarificationFixtures::scenario();
    app(ClarificationGate::class)->pauseIfNeeded($s['posting'], $s['analysis'], $s['match'], 'run-1');
    $question = ClarificationQuestion::query()->where('topic_key', 'webhooks')->latest('id')->firstOrFail();
    $proposal = app(SubmitClarificationAnswer::class)->submit($question, 'experience', ['integrated', 'supported'], REVISION_ANSWER);
    $proposal = app(ReviewEvidenceProposal::class)->approve($proposal, ['visibility' => 'restricted']);

    return [$s, $proposal->careerFact, $proposal];
}

function revise(CareerFact $fact, array $fields, array $options = []): EvidenceProposal
{
    return app(ReviseApprovedEvidence::class)->revise($fact, $fields, $options);
}

it('creates a new revision instead of overwriting: original answer, earlier wording and history all stay', function () {
    [, $fact, $first] = approvedWebhookFact();
    $before = EvidenceRevision::current($fact->careerProfile);

    $second = revise($fact, ['statement' => 'Worked with merchant webhook integrations, including retry schedules and account updates.', 'scopes' => ['integrated', 'supported', 'advised']]);

    $first->refresh();
    $fact->refresh();

    expect($second->revision)->toBe(2)
        ->and($second->revises_proposal_id)->toBe($first->id)
        ->and($second->status)->toBe(ProposalStatus::Approved)
        ->and($second->clarification_answer_id)->toBe($first->clarification_answer_id)
        ->and($first->status)->toBe(ProposalStatus::Superseded)
        ->and($first->statement)->toBe('Worked with merchant integrations involving webhooks for retry schedules, account management, and related functionality.')
        ->and(collect($first->history)->last()['previous']['statement'])->toBe($first->statement)
        ->and(collect($first->history)->last()['by_proposal_id'])->toBe($second->id)
        // The fact keeps its stable key and shows the current revision.
        ->and($fact->key)->toBe('rocketgate-webhooks-user-confirmed')
        ->and($fact->statement)->toBe($second->statement)
        ->and($fact->evidence()->count())->toBe(2)
        ->and($fact->evidence()->orderByDesc('id')->first()->metadata)->toMatchArray(['revision' => 2, 'scopes' => ['integrated', 'supported', 'advised']])
        // Every revision quotes the user's original answer, never the edited wording.
        ->and($fact->evidence()->pluck('quoted_text')->unique()->all())->toBe([$first->answer->answer_text])
        ->and(EvidenceRevision::current($fact->careerProfile))->toBeGreaterThan($before);
});

it('revises visibility and attribution, and can only change what the user asks to change', function () {
    [$s, $fact] = approvedWebhookFact();

    $revised = revise($fact, ['visibility' => 'public', 'attributable_type' => 'role', 'attributable_id' => $s['olderRole']->id]);

    $fact->refresh();
    expect($fact->visibility)->toBe(Visibility::Public)
        ->and($fact->attributable_id)->toBe($s['olderRole']->id)
        ->and($fact->verification)->toBe(Verification::StronglySupported)
        ->and($revised->scopes)->toBe(['integrated', 'supported']);
});

it('refuses an edit that changes nothing', function () {
    [, $fact] = approvedWebhookFact();

    expect(fn () => revise($fact, ['visibility' => 'restricted']))->toThrow(ValidationException::class, 'Nothing changed');
    expect(EvidenceProposal::query()->where('career_fact_id', $fact->id)->count())->toBe(1);
});

it('applies the same scope guard to a revision as to a first approval', function () {
    [, $fact] = approvedWebhookFact();

    expect(fn () => revise($fact, ['statement' => 'Built webhook handlers for merchants.']))->toThrow(ValidationException::class);
    expect(fn () => revise($fact, ['scopes' => []]))->toThrow(ValidationException::class, 'Select what you did');

    // Claiming implementation is fine once the user declares it.
    $revised = revise($fact, ['statement' => 'Built webhook handlers for merchants.', 'scopes' => ['implemented']]);
    expect($revised->revision)->toBe(2);
});

it('prevents a second active fact for the same topic and attribution', function () {
    [$s, $fact] = approvedWebhookFact();

    // A webhook fact on the other role.
    $older = CareerFact::factory()->create([
        'career_profile_id' => $s['profile']->id, 'key' => 'older-webhooks', 'statement' => 'Configured webhooks at Pearson.', 'attributable_type' => 'role', 'attributable_id' => $s['olderRole']->id,
    ]);
    EvidenceProposal::create([
        'career_profile_id' => $s['profile']->id, 'kind' => 'fact', 'status' => 'approved', 'topic_key' => 'webhooks', 'topic_label' => 'Webhooks',
        'statement' => $older->statement, 'scopes' => ['configured'], 'attributable_type' => 'role', 'attributable_id' => $s['olderRole']->id,
        'visibility' => 'public', 'verification' => 'strongly_supported', 'career_fact_id' => $older->id, 'history' => [],
    ]);

    expect(fn () => revise($fact, ['attributable_type' => 'role', 'attributable_id' => $s['olderRole']->id]))
        ->toThrow(ValidationException::class, 'already approved evidence on Webhooks');

    expect(fn () => revise($fact, ['statement' => 'Configured webhooks at Pearson.', 'attributable_type' => 'role', 'attributable_id' => $s['olderRole']->id]))
        ->toThrow(ValidationException::class);
    expect($fact->fresh()->attributable_id)->toBe($s['role']->id);
});

it('refuses to revise a fact that did not come from a clarification', function () {
    [$s] = approvedWebhookFact();
    $plain = CareerFact::factory()->create(['career_profile_id' => $s['profile']->id, 'attributable_type' => 'role', 'attributable_id' => $s['role']->id]);

    expect(fn () => revise($plain, ['statement' => 'Something else entirely.']))->toThrow(ValidationException::class, 'Only evidence you confirmed');
});

it('invalidates dependent matches: the match is stale after a revision', function () {
    [$s, $fact] = approvedWebhookFact();
    $s['match']->forceFill([])->getKey();
    DB::table('job_matches')->where('id', $s['match']->id)->update(['evidence_revision' => EvidenceRevision::current($fact->careerProfile)]);

    revise($fact, ['visibility' => 'public']);

    expect((int) DB::table('job_matches')->where('id', $s['match']->id)->value('evidence_revision'))
        ->toBeLessThan(EvidenceRevision::current($fact->careerProfile));
});

it('changes the Selection input after a revision, so an earlier checkpoint cannot be reused', function () {
    [$s, $fact] = approvedWebhookFact();
    $payload = fn () => json_encode(app(ResumeCandidatePayloadBuilder::class)->build($s['match'])['career_facts'] ?? []);
    $before = $payload();

    revise($fact, ['statement' => 'Worked with merchant webhook integrations for retry schedules.', 'visibility' => 'public']);

    expect($payload())->not->toBe($before);
});

it('keeps export and sync consistent: exports the current revision, is idempotent, and reports a conflict for an older file', function () {
    [$s, $fact] = approvedWebhookFact();
    $file = app(ApprovedEvidenceFile::class);
    $profile = $s['profile'];

    $older = $file->export($profile);
    revise($fact, ['statement' => 'Worked with merchant webhook integrations for retry schedules.']);
    $current = $file->export($profile);

    expect($file->encode($file->export($profile)))->toBe($file->encode($current))
        ->and($current['facts'][0]['statement'])->toBe('Worked with merchant webhook integrations for retry schedules.')
        ->and(collect($current['facts'][0]['evidence'])->pluck('metadata.revision')->filter()->values()->all())->toBe([1, 2])
        ->and(count($current['facts'][0]['evidence']))->toBe(2);

    // Syncing the older file must not silently roll the newer revision back.
    $result = $file->sync($profile, $older);
    expect($result['conflicts'])->toHaveCount(1)
        ->and($fact->fresh()->statement)->toBe('Worked with merchant webhook integrations for retry schedules.');
});

it('exposes the evidence for a job with its original answer and revisions, over the real endpoint', function () {
    [$s, $fact] = approvedWebhookFact();

    $this->patch(route('career-facts.revise', $fact), ['statement' => 'Worked with merchant webhook integrations.', 'visibility' => 'public'])->assertRedirect();

    $this->get(route('jobs.show', $s['posting']))->assertInertia(fn ($page) => $page
        ->has('approved_evidence.facts', 1)
        ->where('approved_evidence.facts.0.revision', 2)
        ->where('approved_evidence.facts.0.visibility', 'public')
        ->where('approved_evidence.facts.0.original_answer', REVISION_ANSWER)
        ->has('approved_evidence.facts.0.revisions', 2)
        ->where('approved_evidence.facts.0.revisions.0.current', false)
        ->where('approved_evidence.facts.0.revisions.1.current', true));

    $this->patch(route('career-facts.revise', $fact), ['statement' => 'Built webhook handlers.'])->assertSessionHasErrors('statement');
});
