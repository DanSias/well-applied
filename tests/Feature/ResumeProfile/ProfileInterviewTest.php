<?php

use App\Enums\ClarificationSessionStatus;
use App\Jobs\BuildResumeProfileJob;
use App\Models\Application;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceProposal;
use App\Models\JobPosting;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviseApprovedEvidence;
use App\Support\ResumeProfile\BuildResumeProfile;
use App\Support\ResumeProfile\ProfileInterview;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ResumeProfileFixtures as F;

/** A profile with a sample already aggregated and an interview opened. */
function interviewed(): array
{
    $f = F::career();
    F::sampleWith($f['profile'], [
        ['required' => ['React experience.', 'Build webhook handlers with idempotent retry logic.', 'Kubernetes in production.']],
        ['required' => ['React experience.', 'Build webhook handlers.', 'Kubernetes.']],
        ['required' => ['React experience.', 'Third-party API integration.', 'Kubernetes.'], 'duties' => ['Own features end-to-end.']],
        ['required' => ['Third-party API integration.', 'Terraform.'], 'duties' => ['Own features end-to-end.']],
        ['required' => ['React experience.', 'Third-party API integration.'], 'preferred' => ['GraphQL.']],
    ]);
    app(ProfileInterview::class)->sync($f['profile']->refresh());

    return $f;
}

function profileQuestion(string $topic): ClarificationQuestion
{
    return ClarificationQuestion::query()->where('topic_key', $topic)->firstOrFail();
}

function profileAnswerUrl(array $f, ClarificationQuestion $q): string
{
    return route('resume-profiles.answer', [$f['profile'], $q]);
}

it('opens a ranked, grouped interview of manageable size, grounded in the market', function () {
    $f = interviewed();
    $session = ClarificationSession::query()->where('resume_profile_id', $f['profile']->id)->sole();
    $questions = $session->questions;

    expect($session->status)->toBe(ClarificationSessionStatus::Pending)
        ->and($session->job_posting_id)->toBeNull()
        ->and($questions->count())->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(16)
        ->and($questions->pluck('score')->all())->toBe($questions->pluck('score')->sortDesc()->values()->all())
        ->and($questions->pluck('topic_key')->all())->not->toContain('react')->not->toContain('webhooks');

    $q = profileQuestion('webhook_handlers');
    expect($q->question_text)->toContain('2 of the 5')->toContain('40%')
        ->and($q->question_text)->toContain('Have you done anything like this')
        ->and($q->context['market']['postings'])->toBe(2)
        ->and($q->context['coverage'])->toBe('transferable')
        ->and($q->score_breakdown)->toHaveKey('answerability')
        ->and($q->suggested_attributable_type)->toBe('role')
        ->and($q->suggested_attributable_id)->toBe($f['role']->id)
        ->and($q->suggested_visibility)->toBe('restricted');
});

it('is idempotent: syncing again adds no duplicate question and keeps order', function () {
    $f = interviewed();
    $before = ClarificationQuestion::pluck('topic_key', 'id')->all();

    app(ProfileInterview::class)->sync($f['profile']->refresh());
    app(ProfileInterview::class)->sync($f['profile']->refresh());

    expect(ClarificationQuestion::pluck('topic_key', 'id')->all())->toBe($before)
        ->and(ClarificationSession::count())->toBe(1);
});

it('keeps to the configured topic budget and groups of four', function () {
    $f = F::career();
    config(['resume_profiles.profiles.full-stack-product.interview.max_topics' => 5, 'resume_profiles.profiles.full-stack-product.interview.min_postings' => 1, 'resume_profiles.profiles.full-stack-product.interview.min_share' => 0.0]);
    F::sampleWith($f['profile'], array_fill(0, 3, ['required' => ['Kubernetes.', 'Terraform.', 'GraphQL.', 'Redis.', 'Kafka.', 'Python.', 'Java.', 'Go, Python', 'Third-party API integration.']]));

    app(ProfileInterview::class)->sync($f['profile']->refresh());
    $progress = app(ProfileInterview::class)->progress($f['profile']->refresh());

    expect($progress['total'])->toBe(5)
        ->and($progress['groups'])->toHaveCount(2)
        ->and($progress['groups'][0]['total'])->toBe(4)
        ->and($progress['current_group'])->toBe(1);
});

it('turns an answer into a reviewable proposal that keeps the original answer, with no job attached', function () {
    $f = interviewed();
    $q = profileQuestion('webhook_handlers');

    $this->post(profileAnswerUrl($f, $q), ['mode' => 'experience', 'scopes' => ['implemented', 'troubleshot'], 'answer_text' => 'At RocketGate I wrote the endpoint that receives payment events and retries failed deliveries.'])->assertRedirect();

    $proposal = EvidenceProposal::sole();
    expect($proposal->status->value)->toBe('proposed')
        ->and($proposal->job_posting_id)->toBeNull()
        ->and($proposal->topic_key)->toBe('webhook_handlers')
        ->and($proposal->answer->answer_text)->toContain('wrote the endpoint')
        ->and($proposal->attributable_id)->toBe($f['role']->id)
        ->and($proposal->visibility->value)->toBe('restricted')
        ->and(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0)
        ->and($q->fresh()->status)->toBe('answered');
});

it('protects visibility: approval needs an explicit choice and nothing becomes Public by itself', function () {
    $f = interviewed();
    $this->post(profileAnswerUrl($f, profileQuestion('webhook_handlers')), ['mode' => 'experience', 'scopes' => ['implemented'], 'answer_text' => 'Built the receiving endpoint for payment events.']);
    $proposal = EvidenceProposal::sole();

    $this->post(route('evidence-proposals.approve', $proposal), [])->assertSessionHasErrors('visibility');
    expect(CareerFact::where('key', 'like', '%user-confirmed')->count())->toBe(0);

    $this->post(route('evidence-proposals.approve', $proposal), ['visibility' => 'restricted'])->assertRedirect();
    $fact = CareerFact::where('key', 'like', '%user-confirmed')->sole();

    expect($fact->visibility->value)->toBe('restricted')
        ->and($fact->evidence()->first()->metadata['origin'])->toBe('clarification')
        ->and($fact->evidence()->first()->quoted_text)->toBe('Built the receiving endpoint for payment events.');
});

it('enriches the shared career database, bumps the evidence revision, and covers the topic on return', function () {
    $f = interviewed();
    $revision = EvidenceRevision::current($f['career']);
    $this->post(profileAnswerUrl($f, profileQuestion('webhook_handlers')), ['mode' => 'experience', 'scopes' => ['implemented'], 'answer_text' => 'Built webhook handlers for payment events at RocketGate.']);
    $this->post(route('evidence-proposals.approve', EvidenceProposal::sole()), ['visibility' => 'restricted']);

    expect(EvidenceRevision::current($f['career']))->toBeGreaterThan($revision);

    // The page notices the changed evidence, resolves what is now covered and lists the contribution.
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.coverage.evidence_changed', false)
        ->has('resume_profile.contributed.facts', 1)
        ->where('resume_profile.contributed.facts.0.original_answer', 'Built webhook handlers for payment events at RocketGate.')
        ->where('resume_profile.interview.questions', fn ($qs) => collect($qs)->firstWhere('topic_key', 'webhook_handlers')['status'] === 'answered'));
});

it('invalidates stale interview state when evidence is revised, and re-ranks without asking again', function () {
    $f = interviewed();
    $interview = app(ProfileInterview::class);
    $this->post(profileAnswerUrl($f, profileQuestion('webhook_handlers')), ['mode' => 'experience', 'scopes' => ['implemented'], 'answer_text' => 'Built webhook handlers for payment events.']);
    $this->post(route('evidence-proposals.approve', EvidenceProposal::sole()), ['visibility' => 'restricted']);
    $interview->sync($f['profile']->refresh());
    expect($interview->progress($f['profile']->refresh())['evidence_changed'])->toBeFalse();

    app(ReviseApprovedEvidence::class)->revise(CareerFact::where('key', 'like', '%user-confirmed')->sole(), ['visibility' => 'public']);

    expect($interview->progress($f['profile']->refresh())['evidence_changed'])->toBeTrue();
    $count = ClarificationQuestion::count();
    $interview->sync($f['profile']->refresh());
    expect($interview->progress($f['profile']->refresh())['evidence_changed'])->toBeFalse()
        ->and(ClarificationQuestion::count())->toBeGreaterThanOrEqual($count);
});

it('records "no experience" as a known limitation and never asks about it again', function () {
    $f = interviewed();
    $this->post(profileAnswerUrl($f, profileQuestion('containers')), ['mode' => 'no_experience', 'answer_text' => ''])->assertRedirect();
    $this->post(route('evidence-proposals.approve', EvidenceProposal::sole()), ['visibility' => 'private'])->assertRedirect();

    expect(CareerLimitation::where('topic_key', 'containers')->active()->exists())->toBeTrue();

    app(ProfileInterview::class)->sync($f['profile']->refresh());
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.questions', fn ($qs) => collect($qs)->firstWhere('topic_key', 'containers')['status'] === 'answered')
        ->where('resume_profile.interview.limitations.0.topic', 'Docker and Kubernetes')
        ->where('resume_profile.market', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['capabilities'])->firstWhere('key', 'containers')['coverage'] === 'limitation'));
});

it('lets the user skip, leave and come back: the same questions, progress and a skipped question can be reopened', function () {
    $f = interviewed();
    $q = profileQuestion('containers');
    $total = ClarificationQuestion::count();

    $this->post(route('resume-profiles.skip', [$f['profile'], $q]))->assertRedirect();
    expect($q->fresh()->status)->toBe('skipped');

    // A new request (the user returns later) sees the same persisted interview.
    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.interview.progress.skipped', 1)
        ->where('resume_profile.interview.progress.total', $total)
        ->has('resume_profile.interview.questions', $total));
    expect(app(ProfileInterview::class)->progress($f['profile']->refresh())['current_group'])->toBe(1);

    $this->post(route('resume-profiles.reopen', [$f['profile'], $q]))->assertRedirect();
    expect($q->fresh()->status)->toBe('open');

    // A question answered once cannot be skipped away or reopened.
    $this->post(profileAnswerUrl($f, $q), ['mode' => 'preference', 'answer_text' => 'Lead with delivery.']);
    $this->post(route('resume-profiles.skip', [$f['profile'], $q]))->assertRedirect();
    expect($q->fresh()->status)->toBe('answered');
});

it('refuses a question that belongs to another profile', function () {
    $f = interviewed();
    $session = ClarificationSession::create(['career_profile_id' => $f['career']->id, 'mode' => 'profile', 'status' => ClarificationSessionStatus::Pending]);
    $foreign = $session->questions()->create(['topic_key' => 'x', 'topic_label' => 'X', 'related_finding_ids' => [], 'question_text' => 'Q', 'score' => 0.1, 'score_breakdown' => [], 'context' => [], 'suggested_visibility' => 'restricted', 'status' => 'open']);

    $this->post(route('resume-profiles.skip', [$f['profile'], $foreign]))->assertNotFound();
});

it('shows only eligible evidence: Private facts never appear on the page', function () {
    $f = interviewed();

    $json = $this->get(route('resume-profiles.show', $f['profile']))->getContent();

    expect($json)->not->toContain('ZEBRA')->not->toContain('private-zebra');
});

it('queues the build from the primary action without a second job while one is running', function () {
    Queue::fake();
    $f = F::career();

    $this->post(route('resume-profiles.build', $f['profile']))->assertRedirect();
    Queue::assertPushed(BuildResumeProfileJob::class, fn ($job) => $job->resumeProfileId === $f['profile']->id && ! $job->pilot);

    $f['profile']->samples()->create(['status' => 'collecting', 'target_size' => 20, 'extraction_version' => 'x']);
    $this->post(route('resume-profiles.build', $f['profile']))->assertRedirect();
    Queue::assertPushed(BuildResumeProfileJob::class, 1);

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page->where('resume_profile.build.state', 'building'));
});

it('builds end to end with fakes: sample, requirements, ranked interview; no tracking records and no model call', function () {
    Http::fake();
    $f = F::career();
    F::providers(['himalayas' => array_map(fn (int $i) => F::candidate('himalayas', "h{$i}", "Co {$i}", "Senior Full Stack Engineer {$i}", F::description(['React and Node.js experience.', 'Build webhook handlers.', 'Kubernetes.'], unique: "e2e{$i}")), range(1, 6)), 'jobicy' => []]);
    $jobs = JobPosting::count();

    $result = app(BuildResumeProfile::class)->run($f['profile'], pilot: true);

    expect($result['included'])->toBe(6)
        ->and($result['questions'])->toBeGreaterThan(0)
        ->and($result['extraction'])->toMatchArray(['extracted' => 6, 'cached' => 0])
        ->and(JobPosting::count())->toBe($jobs)
        ->and(Application::count())->toBe(0)
        ->and($f['profile']->fresh()->status->value)->toBe('interviewing');
    Http::assertNothingSent();

    $this->get(route('resume-profiles.show', $f['profile']))->assertInertia(fn ($page) => $page
        ->where('resume_profile.build.state', 'ready')
        ->where('resume_profile.sample.size', 6)
        ->has('resume_profile.market')
        ->has('resume_profile.sample.postings', 6));
});
