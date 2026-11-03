<?php

namespace App\Support\Clarification;

use App\Enums\EvidenceSource;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\EvidenceProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revises user-confirmed career evidence without overwriting its history.
 *
 * A revision is a NEW EvidenceProposal (approved, `revision` + 1) that points
 * at the one it replaces; the replaced proposal is marked Superseded and
 * keeps what it said. Every revision shares the original clarification
 * answer, so the user's own words are never lost, and each one appends its
 * own `user_confirmed` evidence row carrying the scopes then in force.
 * The CareerFact keeps its stable key and always reflects the current
 * revision.
 *
 * Every revision goes through the same rules as a first approval (scope
 * guard against the original answer, explicit visibility and attribution,
 * duplicate refusal) and bumps the profile's evidence revision, so matches
 * are re-run and Selection checkpoints can no longer match.
 */
final class ReviseApprovedEvidence
{
    public function __construct(private readonly ReviewEvidenceProposal $review, private readonly ScopeGuard $scopeGuard) {}

    /**
     * @param  array<string, mixed>  $fields  statement, scopes, attributable_type, attributable_id, visibility, verification (omitted keys keep their current value)
     * @param  array{acknowledge_metric?: bool, note?: string, shared?: string}  $options  `note` records why, in the user's words, on the new evidence row. `shared`: for a fact that is one claim attached to several places, `all` (default) applies a change of wording, scope or verification to every sibling place so none is left contradicting; `this_only` revises just this place and detaches it from the shared claim; `none` is used internally.
     */
    public function revise(CareerFact $fact, array $fields, array $options = []): EvidenceProposal
    {
        return DB::transaction(function () use ($fact, $fields, $options) {
            $current = $this->currentRevision($fact);
            $answerText = $current->clarification_answer_id === null ? $current->statement : $current->answer->answer_text;

            $next = $current->replicate(['id', 'history', 'created_at', 'updated_at']);
            $next->fill($this->merge($current, $fields));
            $next->revision = $current->revision + 1;
            $next->revises_proposal_id = $current->id;
            $next->fill(['status' => ProposalStatus::Approved, 'decided_at' => now()]);

            $changed = $this->changedFields($current, $next);

            if ($changed === []) {
                throw ValidationException::withMessages(['statement' => 'Nothing changed, so no new revision was created.']);
            }

            $this->validate($fact, $next, $answerText, $options);

            $target = $this->review->resolveAttribution($next);

            $previous = [
                'statement' => $current->statement,
                'scopes' => $current->scopes,
                'attributable_type' => $current->attributable_type,
                'attributable_id' => $current->attributable_id,
                'visibility' => $current->visibility->value,
                'verification' => $current->verification->value,
            ];

            $next->history = [];
            $next->recordHistory('revised', ['from_proposal_id' => $current->id, 'revision' => $next->revision, 'fields' => $changed]);
            $next->recordHistory('approved', ['visibility' => $next->visibility->value, 'verification' => $next->verification->value]);
            $next->save();

            $current->status = ProposalStatus::Superseded;
            $current->recordHistory('superseded', ['by_proposal_id' => $next->id, 'by_revision' => $next->revision, 'fields' => $changed, 'previous' => $previous]);
            $current->save();

            $fact->update([
                'statement' => $next->statement,
                'visibility' => $next->visibility,
                'verification' => $next->verification,
                'notes' => $this->review->scopeNote($next->scopes),
                'attributable_type' => $target->getMorphClass(),
                'attributable_id' => $target->getKey(),
            ]);

            $fact->evidence()->create([
                'source' => EvidenceSource::UserConfirmed,
                'quoted_text' => $answerText,
                'confirmed_at' => now(),
                'note' => 'Revised by the user (revision '.$next->revision.': '.implode(', ', $changed).').'.(isset($options['note']) && $options['note'] !== '' ? ' '.$options['note'] : ''),
                'metadata' => [
                    'origin' => ReviewEvidenceProposal::EVIDENCE_ORIGIN,
                    'topic_key' => $next->topic_key,
                    'scopes' => $next->scopes,
                    'revision' => $next->revision,
                    'proposal_id' => $next->id,
                    'clarification_answer_id' => $next->clarification_answer_id,
                    'job_posting_id' => $next->job_posting_id,
                ],
            ]);

            $this->settleSiblings($fact, $fields, $changed, $options);

            EvidenceRevision::bump(CareerProfile::query()->findOrFail($fact->career_profile_id));

            return $next;
        });
    }

    /**
     * Keeps the places of one shared claim consistent. A change of wording, scope or verification
     * either reaches every sibling (each gets its own revision and history) or, by explicit choice,
     * detaches this place into an independent fact. Visibility and attribution are per place and
     * never propagate.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $changed
     * @param  array<string, mixed>  $options
     */
    private function settleSiblings(CareerFact $fact, array $fields, array $changed, array $options): void
    {
        $mode = (string) ($options['shared'] ?? 'all');

        if ($fact->claim_group === null || $mode === 'none' || array_intersect($changed, ['statement', 'scopes', 'verification']) === []) {
            return;
        }

        if ($mode === 'this_only') {
            $fact->update(['claim_group' => null]);

            return;
        }

        $carry = array_intersect_key($fields, array_flip(['statement', 'scopes', 'verification']));

        foreach (CareerFact::query()->where('claim_group', $fact->claim_group)->where('id', '!=', $fact->id)->get() as $sibling) {
            try {
                $this->revise($sibling, $carry, [...$options, 'shared' => 'none', 'note' => trim(($options['note'] ?? '').' Applied from the shared claim ['.$fact->key.'].')]);
            } catch (ValidationException $e) {
                if (str_contains(implode(' ', array_merge(...array_values($e->errors()))), 'Nothing changed')) {
                    continue;
                }

                throw ValidationException::withMessages(['statement' => "{$sibling->key}: ".implode(' ', array_merge(...array_values($e->errors())))]);
            }
        }
    }

    private function currentRevision(CareerFact $fact): EvidenceProposal
    {
        $current = EvidenceProposal::query()
            ->where('career_fact_id', $fact->id)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->latest('revision')->latest('id')
            ->first();

        return $current ?? throw ValidationException::withMessages(['statement' => 'Only evidence you confirmed through a clarification can be revised here.']);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function merge(EvidenceProposal $current, array $fields): array
    {
        $merged = [];

        if (array_key_exists('statement', $fields)) {
            $statement = trim((string) preg_replace('/\s+/u', ' ', (string) $fields['statement']));

            if ($statement === '') {
                throw ValidationException::withMessages(['statement' => 'The statement cannot be empty.']);
            }

            $merged['statement'] = $statement;
        }

        if (array_key_exists('scopes', $fields)) {
            $allowed = array_diff(array_column(ResponsibilityScope::cases(), 'value'), [ResponsibilityScope::NoExperience->value]);
            $merged['scopes'] = array_values(array_intersect((array) $fields['scopes'], $allowed));
        }

        if (array_key_exists('attributable_type', $fields)) {
            $merged['attributable_type'] = $fields['attributable_type'];
            $merged['attributable_id'] = $fields['attributable_id'] ?? null;
        }

        if (array_key_exists('visibility', $fields)) {
            $merged['visibility'] = Visibility::from((string) $fields['visibility']);
        }

        if (array_key_exists('verification', $fields)) {
            $merged['verification'] = Verification::from((string) $fields['verification']);
        }

        return $merged;
    }

    /**
     * @return array<int, string>
     */
    private function changedFields(EvidenceProposal $current, EvidenceProposal $next): array
    {
        $changed = [];

        $current->statement !== $next->statement && $changed[] = 'statement';
        $current->scopes !== $next->scopes && $changed[] = 'scopes';
        ($current->attributable_type !== $next->attributable_type || (int) $current->attributable_id !== (int) $next->attributable_id) && $changed[] = 'attribution';
        $current->visibility !== $next->visibility && $changed[] = 'visibility';
        $current->verification !== $next->verification && $changed[] = 'verification';

        return $changed;
    }

    /**
     * @param  array{acknowledge_metric?: bool}  $options
     */
    private function validate(CareerFact $fact, EvidenceProposal $next, string $answerText, array $options): void
    {
        if ($next->scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Select what you did.']);
        }

        $problems = $this->scopeGuard->violations($next->statement, $next->scopes, $answerText);

        if ($problems !== []) {
            throw ValidationException::withMessages(['statement' => $problems]);
        }

        if ($next->verification === Verification::Verified
            && preg_match('/\d/', $next->statement.' '.$answerText) === 1
            && empty($options['acknowledge_metric'])) {
            throw ValidationException::withMessages(['verification' => 'This contains a figure you reported yourself. It stays "strongly supported" unless you confirm you can substantiate it.']);
        }

        $target = $this->review->resolveAttribution($next);

        // One active fact per topic and attribution, and no identical statement beside it.
        $otherFacts = CareerFact::query()
            ->where('career_profile_id', $fact->career_profile_id)
            ->where('attributable_type', $target->getMorphClass())
            ->where('attributable_id', $target->getKey())
            ->where('id', '!=', $fact->id)
            ->get();

        foreach ($otherFacts as $other) {
            if ($this->review->normalize($other->statement) === $this->review->normalize($next->statement)) {
                throw ValidationException::withMessages(['statement' => "This already exists as [{$other->key}]."]);
            }
        }

        $sameTopic = EvidenceProposal::query()
            ->where('career_profile_id', $fact->career_profile_id)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->where('topic_key', $next->topic_key)
            ->where('attributable_type', $target->getMorphClass())
            ->where('attributable_id', $target->getKey())
            ->where('career_fact_id', '!=', $fact->id)
            ->with('careerFact')
            ->first();

        if ($sameTopic !== null) {
            throw ValidationException::withMessages(['attributable_type' => "There is already approved evidence on {$next->topic_label} for that role or project: [{$sameTopic->careerFact?->key}]. Revise that one instead."]);
        }
    }
}
