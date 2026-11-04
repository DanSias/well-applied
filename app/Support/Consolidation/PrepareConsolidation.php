<?php

namespace App\Support\Consolidation;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceConsolidation;
use App\Models\EvidenceProposal;
use App\Models\Project;
use App\Models\ResumeProfile;
use App\Models\Role;
use App\Support\Clarification\ScopeGuard;
use App\Support\ResumeProfile\ProfileInterview;

/**
 * Turns the reviewed ConsolidationPlan into review items for one career. Idempotent: an item that
 * already exists is never touched, so a decision or an edit the user made is never overwritten.
 * Creates only review items and interview questions; no career fact, proposal or answer changes.
 */
final class PrepareConsolidation
{
    public function __construct(private readonly ScopeGuard $scopeGuard, private readonly ProfileInterview $interview) {}

    /**
     * @return array{created: int, existing: int, problems: array<int, string>, questions: int}
     */
    public function run(CareerProfile $career, ?ResumeProfile $profile = null, bool $dryRun = false): array
    {
        $created = 0;
        $existing = 0;
        $problems = [];
        $order = 0;

        foreach (ConsolidationPlan::items() as $definition) {
            $order++;

            if (EvidenceConsolidation::query()->where('career_profile_id', $career->id)->where('code', $definition['code'])->exists()) {
                $existing++;

                continue;
            }

            try {
                $attributes = $this->resolve($career, $definition, $order, $profile, $dryRun);
            } catch (\RuntimeException $e) {
                $problems[] = "{$definition['code']}: {$e->getMessage()}";

                continue;
            }

            if (! $dryRun) {
                EvidenceConsolidation::create($attributes);
            }

            $created++;
        }

        return ['created' => $created, 'existing' => $existing, 'problems' => $problems, 'questions' => $profile === null ? 0 : $this->questions($career, $profile, $dryRun)];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function resolve(CareerProfile $career, array $d, int $order, ?ResumeProfile $profile = null, bool $dryRun = false): array
    {
        $row = [
            'career_profile_id' => $career->id, 'code' => $d['code'], 'theme' => $d['theme'], 'action' => $d['action'], 'status' => 'proposed', 'sort_order' => $order,
            'title' => $d['title'], 'summary' => $d['summary'] ?? null, 'notes' => $d['notes'] ?? null,
            'skills' => $d['skills'] ?? null,
            'history' => [['at' => now()->toIso8601String(), 'action' => 'prepared']],
            'links' => isset($d['links']) ? array_values(array_map(fn (string $key) => $this->fact($career, $key)->id, $d['links'])) : null,
        ];

        if ($d['action'] === 'advice') {
            $proposal = EvidenceProposal::query()->where('career_profile_id', $career->id)->where('kind', ProposalKind::Fact)->whereKey((int) $d['proposal'])->first();

            if ($proposal === null || $proposal->status !== ProposalStatus::Proposed) {
                throw new \RuntimeException("proposal #{$d['proposal']} is not an undecided proposal");
            }

            return [...$row, 'target_proposal_id' => $proposal->id, 'statement' => $proposal->statement];
        }

        if ($d['action'] === 'tag') {
            return [...$row, 'fact_ids' => array_values(array_map(fn (string $key) => $this->fact($career, $key)->id, $d['fact_keys']))];
        }

        $target = $d['action'] === 'revise' ? $this->fact($career, $d['fact']) : null;
        $source = $target ?? $this->fact($career, $d['source']);
        $statement = $this->token($career, (string) $d['statement']);
        $current = $this->approvedProposal($source);
        $confirmation = $d['confirmation'] ?? null;
        $adopts = false;

        if ($current === null) {
            // A fact that came from the resume and portfolio, not from an interview answer, has no revision chain yet.
            // The user's confirmation is recorded as an interview answer, and approving the item makes it the first
            // revision, so the original wording, sources and metric stay on record and no second fact is created.
            if ($confirmation === null) {
                throw new \RuntimeException("[{$source->key}] was not confirmed through an interview answer, and no confirmation was given");
            }

            $adopts = true;
            $answerId = $this->recordConfirmation($profile, $d, $confirmation, $dryRun);
            $answerText = (string) $confirmation['answer'];
            $scopes = $d['scopes'];
            $topic = (string) $confirmation['topic_key'];
        } else {
            $answerId = $current->clarification_answer_id;
            $answerText = $current->clarification_answer_id === null ? null : (string) $current->answer->answer_text;
            $scopes = $d['scopes'] ?? $current->scopes;
            $topic = (string) ($d['topic'] ?? $current->topic_key);
        }

        if ($answerText !== null) {
            $problems = $this->scopeGuard->violations($statement, $scopes, $answerText);

            if ($problems !== []) {
                throw new \RuntimeException('wording exceeds the answer: '.implode(' ', $problems));
            }
        }

        $place = $d['place'] === null ? null : $this->place($career, $d['place']);
        $decision = isset($d['decision']) ? [
            'required' => $d['decision']['required'], 'question' => $d['decision']['question'],
            'candidates' => array_values(array_map(fn (array $p) => $this->place($career, $p), $d['decision']['candidates'])),
        ] : null;
        $group = isset($d['group']) ? $this->token($career, (string) $d['group']) : null;

        return [
            ...$row,
            'target_fact_id' => $target?->id, 'source_answer_id' => $answerId,
            'topic_key' => $topic, 'statement' => $statement, 'scopes' => $scopes,
            'attributable_type' => $place['type'] ?? null, 'attributable_id' => $place['id'] ?? null,
            'visibility' => $d['visibility'] ?? $target?->visibility->value ?? $current?->visibility->value,
            'verification' => $target?->verification->value ?? $current?->verification->value,
            'claim_group' => $group, 'decision' => $decision,
            'notes' => [...($d['notes'] ?? []), ...(isset($d['topic_label']) ? ['topic_label' => $d['topic_label']] : []), ...($adopts ? ['topic_label' => $confirmation['topic_label'], 'adopts_original' => true] : [])],
        ];
    }

    private function token(CareerProfile $career, string $value): string
    {
        if (preg_match('/^@(statement|group)-of:(.+)$/', $value, $m) !== 1) {
            return $value;
        }

        $fact = $this->fact($career, $m[2]);

        if ($m[1] === 'statement') {
            return $fact->statement;
        }

        return $fact->claim_group ?? throw new \RuntimeException("[{$fact->key}] is not part of a shared claim");
    }

    private function fact(CareerProfile $career, string $key): CareerFact
    {
        return CareerFact::query()->where('career_profile_id', $career->id)->where('key', $key)->first() ?? throw new \RuntimeException("fact [{$key}] not found");
    }

    private function approvedProposal(CareerFact $fact): ?EvidenceProposal
    {
        return EvidenceProposal::query()->where('career_fact_id', $fact->id)->where('kind', ProposalKind::Fact)->where('status', ProposalStatus::Approved)
            ->latest('revision')->latest('id')->first();
    }

    /**
     * Records what the user told us as an answered interview question, once. Nothing is proposed from it directly: the
     * consolidation item that cites it is the only thing that can change a fact, and only when approved.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, string>  $confirmation
     */
    private function recordConfirmation(?ResumeProfile $profile, array $d, array $confirmation, bool $dryRun): ?int
    {
        $session = $profile === null ? null : $this->interview->session($profile);

        if ($session === null) {
            throw new \RuntimeException('recording a confirmation needs the profile interview');
        }

        $existing = $session->questions()->where('context->code', $d['code'])->first();

        if ($existing !== null) {
            return $existing->answers()->latest('id')->value('id');
        }

        if ($dryRun) {
            return null;
        }

        $question = $session->questions()->create([
            'topic_key' => $confirmation['topic_key'], 'topic_label' => $confirmation['topic_label'], 'related_finding_ids' => [], 'question_text' => $confirmation['question'],
            'score' => 0.5, 'score_breakdown' => ['source' => 'confirmation'],
            'context' => ['source' => 'confirmation', 'kind' => 'confirmation', 'code' => $d['code'], 'refs' => [], 'related_evidence' => []],
            'suggested_visibility' => 'public', 'status' => 'answered', 'sort_order' => (int) $session->questions()->max('sort_order') + 1,
        ]);

        return $question->answers()->create(['mode' => 'experience', 'scopes' => $d['scopes'], 'answer_text' => $confirmation['answer']])->id;
    }

    /**
     * @param  array<int, string>  $place
     * @return array{type: string, id: int}
     */
    private function place(CareerProfile $career, array $place): array
    {
        if ($place[0] === 'profile') {
            return ['type' => 'career_profile', 'id' => $career->id];
        }

        $found = $place[0] === 'project'
            ? Project::query()->where('career_profile_id', $career->id)->where('name', $place[1])->first()
            : Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $career->id))->where('title', $place[1])->first();

        if ($found === null) {
            throw new \RuntimeException("{$place[0]} [{$place[1]}] not found");
        }

        return ['type' => $place[0], 'id' => (int) $found->getKey()];
    }

    private function questions(CareerProfile $career, ResumeProfile $profile, bool $dryRun): int
    {
        $session = $this->interview->session($profile);

        if ($session === null) {
            return 0;
        }

        $added = 0;

        foreach (ConsolidationPlan::questions() as $q) {
            if ($session->questions()->where('context->code', $q['code'])->exists()) {
                continue;
            }

            try {
                $place = $this->place($career, $q['place']);
            } catch (\RuntimeException) {
                continue;
            }

            if (! $dryRun) {
                $this->createQuestion($session, $q, $place);
            }

            $added++;
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $q
     * @param  array{type: string, id: int}  $place
     */
    private function createQuestion(ClarificationSession $session, array $q, array $place): ClarificationQuestion
    {
        $label = match ($place['type']) {
            'project' => Project::query()->find($place['id'])?->name,
            'role' => Role::query()->find($place['id'])?->title,
            default => null,
        };

        return $session->questions()->create([
            'topic_key' => $q['topic_key'], 'topic_label' => $q['topic_label'], 'related_finding_ids' => [], 'question_text' => $q['text'],
            'score' => 0.5, 'score_breakdown' => ['source' => 'followup'],
            // `followup` keeps the interview from retiring it when the market ranking changes.
            'context' => ['source' => 'followup', 'kind' => 'deepening', 'code' => $q['code'], 'refs' => $label === null ? [] : [['type' => $place['type'], 'id' => $place['id'], 'label' => $label]], 'related_evidence' => []],
            'suggested_attributable_type' => $place['type'] === 'career_profile' ? null : $place['type'], 'suggested_attributable_id' => $place['type'] === 'career_profile' ? null : $place['id'],
            'suggested_visibility' => 'restricted', 'status' => 'open', 'sort_order' => (int) $session->questions()->max('sort_order') + 1,
        ]);
    }
}
