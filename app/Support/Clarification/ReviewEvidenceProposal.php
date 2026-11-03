<?php

namespace App\Support\Clarification;

use App\Enums\CareerFactType;
use App\Enums\EvidenceSource;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\EvidenceProposal;
use App\Models\JobPosting;
use App\Models\PresentationPreference;
use App\Models\Project;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way a proposal becomes canonical. Edit, approve or reject; every
 * step is appended to the proposal's history, and approval is the single
 * place a CareerFact, CareerLimitation or PresentationPreference is
 * created.
 *
 * Approval is deliberately explicit: the visibility must be passed in by
 * the caller (a stored default is never enough), the wording is checked
 * against the selected scope, and an existing fact, duplicate or
 * contradicting limitation stops the approval until the user resolves it.
 * Existing facts are never changed unless the caller opts in to replacing
 * one (`supersede`).
 */
final class ReviewEvidenceProposal
{
    public const EVIDENCE_ORIGIN = 'clarification';

    public function __construct(private readonly ScopeGuard $scopeGuard, private readonly TopicCatalog $topics) {}

    /**
     * Edits a proposal that has not been decided yet.
     *
     * @param  array<string, mixed>  $fields  statement, scopes, attributable_type, attributable_id, visibility, verification
     */
    public function update(EvidenceProposal $proposal, array $fields): EvidenceProposal
    {
        $this->assertOpen($proposal);

        $changed = [];

        if (array_key_exists('statement', $fields)) {
            $statement = trim((string) preg_replace('/\s+/u', ' ', (string) $fields['statement']));

            if ($statement === '') {
                throw ValidationException::withMessages(['statement' => 'The statement cannot be empty.']);
            }

            $proposal->statement = $statement;
            $changed[] = 'statement';
        }

        if ($proposal->kind === ProposalKind::Fact) {
            if (array_key_exists('scopes', $fields)) {
                $allowed = array_diff(array_column(ResponsibilityScope::cases(), 'value'), [ResponsibilityScope::NoExperience->value]);
                $proposal->scopes = array_values(array_intersect((array) $fields['scopes'], $allowed));
                $changed[] = 'scopes';
            }

            if (array_key_exists('attributable_type', $fields)) {
                $proposal->attributable_type = $fields['attributable_type'] === null ? null : (string) $fields['attributable_type'];
                $proposal->attributable_id = $fields['attributable_id'] === null ? null : (int) $fields['attributable_id'];
                $changed[] = 'attribution';
            }

            if (array_key_exists('visibility', $fields)) {
                $proposal->visibility = Visibility::from((string) $fields['visibility']);
                $changed[] = 'visibility';
            }

            if (array_key_exists('verification', $fields)) {
                $proposal->verification = Verification::from((string) $fields['verification']);
                $changed[] = 'verification';
            }
        }

        if ($changed !== []) {
            $proposal->recordHistory('edited', ['fields' => $changed]);
            $proposal->save();
        }

        return $proposal;
    }

    public function reject(EvidenceProposal $proposal): EvidenceProposal
    {
        $this->assertOpen($proposal);

        $proposal->fill(['status' => ProposalStatus::Rejected, 'decided_at' => now()]);
        $proposal->recordHistory('rejected');
        $proposal->save();

        // A rejected answer is a "not now" for the topic: the question is
        // treated as skipped so the same topic is not asked again at once.
        $question = $proposal->answer?->question;
        $stillOpen = $question === null ? false : EvidenceProposal::query()
            ->whereIn('clarification_answer_id', $question->answers()->pluck('id'))
            ->whereIn('status', [ProposalStatus::Proposed, ProposalStatus::Approved])
            ->exists();

        if ($question !== null && ! $stillOpen) {
            $question->update(['status' => 'skipped']);
        }

        return $proposal;
    }

    /**
     * @param  array<string, mixed>  $fields  same keys as update(); `visibility` is required
     * @param  array{supersede?: bool, revoke_limitation?: bool, acknowledge_metric?: bool}  $options
     */
    public function approve(EvidenceProposal $proposal, array $fields, array $options = []): EvidenceProposal
    {
        $this->assertOpen($proposal);

        return DB::transaction(function () use ($proposal, $fields, $options) {
            if ($proposal->kind === ProposalKind::Fact && ! isset($fields['visibility'])) {
                throw ValidationException::withMessages(['visibility' => 'Choose a visibility before approving. It is never assumed.']);
            }

            $proposal = $this->update($proposal, $fields);

            match ($proposal->kind) {
                ProposalKind::Fact => $this->approveFact($proposal, $options),
                ProposalKind::Limitation => $this->approveLimitation($proposal),
                ProposalKind::Preference => $this->approvePreference($proposal),
            };

            $proposal->fill(['status' => ProposalStatus::Approved, 'decided_at' => now()]);
            $proposal->recordHistory('approved', [
                'visibility' => $proposal->visibility->value,
                'verification' => $proposal->verification->value,
            ]);
            $proposal->save();

            return $proposal;
        });
    }

    /**
     * @param  array{supersede?: bool, revoke_limitation?: bool, acknowledge_metric?: bool}  $options
     */
    private function approveFact(EvidenceProposal $proposal, array $options): void
    {
        $profile = CareerProfile::query()->findOrFail($proposal->career_profile_id);
        $answerText = $proposal->clarification_answer_id === null ? $proposal->statement : $proposal->answer->answer_text;

        if ($proposal->scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Select what you did before approving.']);
        }

        $problems = $this->scopeGuard->violations($proposal->statement, $proposal->scopes, $answerText);

        if ($problems !== []) {
            throw ValidationException::withMessages(['statement' => $problems]);
        }

        $target = $this->resolveAttribution($proposal);

        if ($proposal->verification === Verification::Verified
            && preg_match('/\d/', $proposal->statement.' '.$answerText) === 1
            && empty($options['acknowledge_metric'])) {
            throw ValidationException::withMessages(['verification' => 'This answer contains a figure you reported yourself. It is saved as "strongly supported" unless you confirm you can substantiate it.']);
        }

        $limitation = CareerLimitation::query()
            ->where('career_profile_id', $profile->id)
            ->where('topic_key', $proposal->topic_key)
            ->active()
            ->first();

        if ($limitation !== null) {
            if (empty($options['revoke_limitation'])) {
                throw ValidationException::withMessages(['statement' => "You previously confirmed: {$limitation->topic}, {$limitation->status}. Revoke that limitation to record experience on this topic."]);
            }

            $limitation->update(['revoked_at' => now()]);
        }

        $normalized = $this->normalize($proposal->statement);
        $existing = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->where('attributable_type', $target->getMorphClass())
            ->where('attributable_id', $target->getKey())
            ->get();

        foreach ($existing as $fact) {
            if ($this->normalize($fact->statement) === $normalized) {
                throw ValidationException::withMessages(['statement' => "This is already recorded as [{$fact->key}]. Nothing was added."]);
            }
        }

        $sameTopic = EvidenceProposal::query()
            ->where('career_profile_id', $profile->id)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->where('topic_key', $proposal->topic_key)
            ->where('attributable_type', $target->getMorphClass())
            ->where('attributable_id', $target->getKey())
            ->whereHas('careerFact')
            ->with('careerFact')
            ->latest('id')
            ->first();

        if ($sameTopic !== null && empty($options['supersede'])) {
            throw ValidationException::withMessages(['statement' => "You already approved evidence on this topic for this role: \"{$sameTopic->careerFact->statement}\". Replace it explicitly, or reject this proposal."]);
        }

        $note = $this->scopeNote($proposal->scopes);

        if ($sameTopic !== null) {
            $fact = $sameTopic->careerFact;
            $previousStatement = $fact->statement;
            $fact->update([
                'statement' => $proposal->statement,
                'verification' => $proposal->verification,
                'visibility' => $proposal->visibility,
                'notes' => $note,
            ]);
            $sameTopic->status = ProposalStatus::Superseded;
            $sameTopic->recordHistory('superseded', ['by_proposal_id' => $proposal->id]);
            $sameTopic->save();
            $proposal->revision = $sameTopic->revision + 1;
            $proposal->revises_proposal_id = $sameTopic->id;
            $proposal->recordHistory('replaced_fact', ['career_fact_id' => $fact->id, 'previous_statement' => $previousStatement]);
        } else {
            $fact = CareerFact::create([
                'career_profile_id' => $profile->id,
                'key' => $this->uniqueKey($profile->id, $target, $proposal->topic_key),
                'fact_type' => CareerFactType::Bullet,
                'statement' => $proposal->statement,
                'verification' => $proposal->verification,
                'visibility' => $proposal->visibility,
                'notes' => $note,
                'sort_order' => (int) CareerFact::query()
                    ->where('attributable_type', $target->getMorphClass())
                    ->where('attributable_id', $target->getKey())
                    ->max('sort_order') + 1,
                'attributable_type' => $target->getMorphClass(),
                'attributable_id' => $target->getKey(),
            ]);
        }

        $job = $proposal->job_posting_id === null ? null : JobPosting::query()->find($proposal->job_posting_id);

        $fact->evidence()->create([
            'source' => EvidenceSource::UserConfirmed,
            'quoted_text' => $answerText,
            'confirmed_at' => now(),
            'note' => $job === null
                ? "Confirmed by the user ({$proposal->topic_label})."
                : "Confirmed by the user while preparing for {$job->company}, {$job->title} (job #{$job->id}): {$proposal->topic_label}.",
            'metadata' => [
                'origin' => self::EVIDENCE_ORIGIN,
                'topic_key' => $proposal->topic_key,
                'scopes' => $proposal->scopes,
                'revision' => (int) ($proposal->revision ?? 1),
                'proposal_id' => $proposal->id,
                'clarification_answer_id' => $proposal->clarification_answer_id,
                'job_posting_id' => $proposal->job_posting_id,
            ],
        ]);

        $proposal->career_fact_id = $fact->id;
        EvidenceRevision::bump($profile);
    }

    private function approveLimitation(EvidenceProposal $proposal): void
    {
        $profile = CareerProfile::query()->findOrFail($proposal->career_profile_id);

        $conflict = EvidenceProposal::query()
            ->where('career_profile_id', $profile->id)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->where('topic_key', $proposal->topic_key)
            ->whereHas('careerFact')
            ->with('careerFact')
            ->first();

        if ($conflict !== null) {
            throw ValidationException::withMessages(['statement' => "This contradicts approved evidence [{$conflict->careerFact->key}]. Reject this proposal, or revise that fact first."]);
        }

        $limitation = CareerLimitation::query()
            ->where('career_profile_id', $profile->id)
            ->where('topic_key', $proposal->topic_key)
            ->active()
            ->first();

        // Already recorded: link to it instead of storing a duplicate.
        $limitation ??= CareerLimitation::create([
            'career_profile_id' => $profile->id,
            'topic_key' => $proposal->topic_key,
            'topic' => $proposal->topic_label,
            'terms' => $this->topics->terms($proposal->topic_key) ?: [$proposal->topic_label],
            'status' => 'no_direct_experience',
            'note' => $proposal->answer?->answer_text,
            'source' => 'user_confirmation',
            'confirmed_at' => now(),
            'source_job_posting_id' => $proposal->job_posting_id,
            'source_answer_id' => $proposal->clarification_answer_id,
        ]);

        $proposal->career_limitation_id = $limitation->id;
    }

    private function approvePreference(EvidenceProposal $proposal): void
    {
        PresentationPreference::query()
            ->where('career_profile_id', $proposal->career_profile_id)
            ->where('topic_key', $proposal->topic_key)
            ->active()
            ->update(['revoked_at' => now()]);

        $preference = PresentationPreference::create([
            'career_profile_id' => $proposal->career_profile_id,
            'topic_key' => $proposal->topic_key,
            'topic' => $proposal->topic_label,
            'guidance' => $proposal->statement,
            'confirmed_at' => now(),
            'source_job_posting_id' => $proposal->job_posting_id,
            'source_answer_id' => $proposal->clarification_answer_id,
        ]);

        $proposal->presentation_preference_id = $preference->id;
    }

    private function assertOpen(EvidenceProposal $proposal): void
    {
        if ($proposal->status !== ProposalStatus::Proposed) {
            throw ValidationException::withMessages(['proposal' => "This proposal was already {$proposal->status->value}."]);
        }
    }

    public function resolveAttribution(EvidenceProposal $proposal): Model
    {
        $class = $proposal->attributable_type === null ? null : Relation::getMorphedModel($proposal->attributable_type);

        if ($class === null || ! in_array($proposal->attributable_type, ['employer', 'role', 'project'], true) || $proposal->attributable_id === null) {
            throw ValidationException::withMessages(['attributable_type' => 'Choose the employer, role or project this belongs to.']);
        }

        $target = $class::query()->find($proposal->attributable_id);

        if (! $target instanceof Model || ! method_exists($target, 'ownerCareerProfileId') || $target->ownerCareerProfileId() !== $proposal->career_profile_id) {
            throw ValidationException::withMessages(['attributable_type' => 'That employer, role or project is not part of your career data.']);
        }

        return $target;
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function scopeNote(array $scopes): string
    {
        $labels = array_map(fn (string $scope) => ResponsibilityScope::from($scope)->label(), $scopes);

        return 'Responsibility scope, as stated by the user: '.implode('; ', $labels).'. Wording must not claim more than this.';
    }

    public function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($text)));
    }

    private function uniqueKey(int $profileId, Model $target, string $topicKey): string
    {
        $owner = match (true) {
            $target instanceof Project => $target->slug,
            $target instanceof Role => Str::slug((string) $target->employer->name),
            $target instanceof Employer => Str::slug($target->name),
            default => 'career',
        };

        $base = Str::slug($owner.'-'.str_replace('_', '-', $topicKey).'-user-confirmed');
        $key = $base;

        for ($n = 2; CareerFact::query()->where('key', $key)->exists(); $n++) {
            $key = "{$base}-{$n}";
        }

        return $key;
    }
}
