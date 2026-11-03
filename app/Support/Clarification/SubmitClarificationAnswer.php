<?php

namespace App\Support\Clarification;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResponsibilityScope;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\ClarificationAnswer;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores a user's answer verbatim and turns it into exactly one reviewable
 * EvidenceProposal. Nothing becomes canonical here: the proposal is a
 * draft until ReviewEvidenceProposal::approve() is called.
 *
 * The answer mode decides the proposal kind:
 *   experience     -> a Fact proposal, scope required
 *   no_experience  -> a Limitation proposal
 *   preference     -> a Preference proposal (presentation guidance only)
 */
final class SubmitClarificationAnswer
{
    public const MODES = ['experience', 'no_experience', 'preference'];

    /**
     * @param  array<int, string>  $scopes
     */
    public function submit(ClarificationQuestion $question, string $mode, array $scopes, string $text): EvidenceProposal
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Choose whether this is experience, no experience, or a presentation preference.']);
        }

        $allowedScopes = array_diff(array_column(ResponsibilityScope::cases(), 'value'), [ResponsibilityScope::NoExperience->value]);
        $scopes = array_values(array_intersect($scopes, $allowedScopes));

        if ($mode === 'experience' && $scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Select what you did, so the wording does not claim more than you did.']);
        }

        if ($mode !== 'no_experience' && $text === '') {
            throw ValidationException::withMessages(['answer_text' => 'Write a short answer in your own words.']);
        }

        return DB::transaction(function () use ($question, $mode, $scopes, $text) {
            $session = $question->session;
            $profile = $session->jobPosting->careerProfile;

            $answer = ClarificationAnswer::create([
                'clarification_question_id' => $question->id,
                'mode' => $mode,
                'scopes' => $mode === 'no_experience' ? [ResponsibilityScope::NoExperience->value] : ($mode === 'experience' ? $scopes : []),
                'answer_text' => $text === '' ? 'No direct experience.' : $text,
            ]);

            // A revised answer replaces any proposal from an earlier one that
            // has not been decided yet; decided proposals are never touched.
            foreach (EvidenceProposal::query()
                ->whereIn('clarification_answer_id', $question->answers()->where('id', '!=', $answer->id)->pluck('id'))
                ->where('status', ProposalStatus::Proposed)
                ->get() as $stale) {
                $stale->status = ProposalStatus::Superseded;
                $stale->recordHistory('superseded', ['by_answer_id' => $answer->id]);
                $stale->save();
            }

            [$type, $id] = $this->attribution($question, $answer->answer_text, $profile->id);
            $isFact = $mode === 'experience';

            // The suggested visibility was derived from evidence in the
            // SUGGESTED role. If the answer names a different employer, that
            // evidence says nothing about the new one, so fall back to the
            // conservative default instead of borrowing it.
            $attributionChanged = $type !== $question->suggested_attributable_type || $id !== $question->suggested_attributable_id;
            $visibility = $isFact && ! $attributionChanged ? Visibility::from($question->suggested_visibility) : ($isFact ? Visibility::Restricted : Visibility::Private);

            $proposal = new EvidenceProposal([
                'career_profile_id' => $profile->id,
                'clarification_answer_id' => $answer->id,
                'job_posting_id' => $session->job_posting_id,
                'job_analysis_finding_id' => $question->job_analysis_finding_id,
                'kind' => match ($mode) {
                    'experience' => ProposalKind::Fact,
                    'no_experience' => ProposalKind::Limitation,
                    default => ProposalKind::Preference,
                },
                'status' => ProposalStatus::Proposed,
                'topic_key' => $question->topic_key,
                'topic_label' => $question->topic_label,
                'statement' => match ($mode) {
                    'experience' => $this->sentence($this->withoutEmployerLead($answer->answer_text, $type, $id)),
                    'no_experience' => "No direct experience with {$question->topic_label}.",
                    default => $this->sentence($answer->answer_text),
                },
                'scopes' => $answer->scopes,
                'attributable_type' => $isFact ? $type : null,
                'attributable_id' => $isFact ? $id : null,
                // Facts start at the conservative suggestion; limitations and
                // preferences are not resume content, so they stay Private.
                'visibility' => $visibility,
                'verification' => Verification::StronglySupported,
                'history' => [],
            ]);
            $proposal->recordHistory('proposed', ['answer_id' => $answer->id, 'question_id' => $question->id]);
            $proposal->save();

            $question->update(['status' => 'answered']);

            return $proposal;
        });
    }

    /**
     * The suggested role, unless the answer names one of the user's
     * employers outright, in which case that employer's most recent role.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function attribution(ClarificationQuestion $question, string $text, int $profileId): array
    {
        $roles = Role::query()
            ->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profileId))
            ->with('employer')
            ->get();

        $named = $roles->filter(function (Role $role) use ($text) {
            foreach (array_filter([$role->employer->name, $role->employer->short_name]) as $name) {
                if (preg_match('/(?<![\w])'.preg_quote($name, '/').'(?![\w])/iu', $text) === 1) {
                    return true;
                }
            }

            return false;
        });

        if ($named->isNotEmpty()) {
            // Most recent first: an open-ended role (no end_year) is the latest.
            $role = $named->sortByDesc(fn (Role $r) => [$r->end_year === null ? 1 : 0, $r->start_year, $r->start_month])->first();

            return ['role', $role->id];
        }

        return [$question->suggested_attributable_type, $question->suggested_attributable_id];
    }

    /**
     * A fact attributed to a role does not repeat its employer ("At
     * RocketGate, ..."): the attribution already says so. Only a leading
     * employer phrase is removed; the rest of the wording is untouched and
     * the verbatim answer is kept as provenance.
     */
    private function withoutEmployerLead(string $text, ?string $type, ?int $id): string
    {
        $role = $type === 'role' && $id !== null ? Role::query()->with('employer')->find($id) : null;

        if ($role === null) {
            return $text;
        }

        foreach (array_filter([$role->employer->name, $role->employer->short_name]) as $name) {
            $stripped = preg_replace('/^at\s+'.preg_quote($name, '/').'\s*[,:]\s*/iu', '', $text, 1, $count);

            if ($count === 1 && is_string($stripped) && $stripped !== '') {
                return mb_strtoupper(mb_substr($stripped, 0, 1)).mb_substr($stripped, 1);
            }
        }

        return $text;
    }

    private function sentence(string $text): string
    {
        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
