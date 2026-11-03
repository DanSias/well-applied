<?php

namespace App\Support\ProfileSynthesis;

use App\Models\ResumeProfileSynthesis;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The user's control over a synthesized profile: include, exclude, pin, reorder and reword, then approve.
 * Edits live in `edits`, never in `content`. Reworded language is judged by the same validator as the
 * model's own, so an edit cannot introduce an unsupported claim. Approval locks the version; changing an
 * approved profile means a new draft version, not an edit in place.
 */
final class ReviewProfileSynthesis
{
    private const TEXT_IDS = '/^(?:headline|summary|differentiator:\d+|note:\d+)$/';

    private const ITEM_IDS = '/^(?:project:\d+|capability:[a-z0-9_]+|accomplishment:[a-z0-9._-]+|differentiator:\d+)$/';

    public function __construct(private readonly ProfilePresentationValidator $validator) {}

    /**
     * @param  array{include?: bool, pinned?: bool, order?: int|null, text?: string}  $fields
     */
    public function edit(ResumeProfileSynthesis $synthesis, string $id, array $fields): ResumeProfileSynthesis
    {
        $this->assertDraft($synthesis);
        $edits = $synthesis->edits ?? ['items' => [], 'text' => []];

        if (array_key_exists('text', $fields)) {
            if (preg_match(self::TEXT_IDS, $id) !== 1) {
                throw ValidationException::withMessages(['text' => 'That item has no wording to edit.']);
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $fields['text']));
            $edits['text'][$id] = $text;
            $this->assertLanguageHolds($synthesis, $edits, $id);
        }

        $decisions = array_intersect_key($fields, array_flip(['include', 'pinned', 'order']));

        if ($decisions !== []) {
            if (preg_match(self::ITEM_IDS, $id) !== 1 || ! $this->exists($synthesis, $id)) {
                throw ValidationException::withMessages(['item' => 'That item is not part of this profile.']);
            }

            $edits['items'][$id] = [...($edits['items'][$id] ?? []), ...array_map(fn ($v) => $v, $decisions)];
        }

        $synthesis->update(['edits' => $edits]);

        return $synthesis->refresh();
    }

    /**
     * Sets the user's order for one list (projects, accomplishments, capabilities or differentiators) in one step.
     *
     * @param  array<int, string>  $ids  every item of the list, in the order the user wants
     */
    public function reorder(ResumeProfileSynthesis $synthesis, array $ids): ResumeProfileSynthesis
    {
        $this->assertDraft($synthesis);

        foreach ($ids as $id) {
            if (preg_match(self::ITEM_IDS, $id) !== 1 || ! $this->exists($synthesis, $id)) {
                throw ValidationException::withMessages(['item' => 'That item is not part of this profile.']);
            }
        }

        $edits = $synthesis->edits ?? ['items' => [], 'text' => []];

        foreach (array_values($ids) as $position => $id) {
            $edits['items'][$id] = [...($edits['items'][$id] ?? []), 'order' => $position];
        }

        $synthesis->update(['edits' => $edits]);

        return $synthesis->refresh();
    }

    public function approve(ResumeProfileSynthesis $synthesis): ResumeProfileSynthesis
    {
        $this->assertDraft($synthesis);

        if ($synthesis->isStale()) {
            throw ValidationException::withMessages(['profile' => 'Your approved evidence changed after this profile was built. Build a new version first.']);
        }

        $effective = EffectiveProfile::resolve($synthesis);

        if (array_filter($effective['projects'], fn (array $p) => $p['included'] && ! $p['personal']) === []) {
            throw ValidationException::withMessages(['profile' => 'Include at least one employment project before approving.']);
        }

        $problems = $this->problems($synthesis, $effective);

        if ($problems !== []) {
            throw ValidationException::withMessages(['profile' => $problems]);
        }

        return DB::transaction(function () use ($synthesis) {
            ResumeProfileSynthesis::query()->where('resume_profile_id', $synthesis->resume_profile_id)->where('status', 'approved')->update(['status' => 'superseded']);
            $synthesis->update(['status' => 'approved', 'approved_at' => now()]);

            return $synthesis->refresh();
        });
    }

    /**
     * A new draft version from an approved or earlier one, with its edits, and no model call.
     */
    public function newVersion(ResumeProfileSynthesis $from): ResumeProfileSynthesis
    {
        return DB::transaction(function () use ($from) {
            ResumeProfileSynthesis::query()->where('resume_profile_id', $from->resume_profile_id)->where('status', 'draft')->update(['status' => 'superseded']);

            return ResumeProfileSynthesis::create([
                ...$from->only(['career_profile_id', 'resume_profile_id', 'evidence_revision', 'market_sample_id', 'pilot', 'input_hash', 'prompt_version', 'generated_by', 'content', 'edits']),
                'version' => (int) ResumeProfileSynthesis::query()->where('resume_profile_id', $from->resume_profile_id)->max('version') + 1,
                'status' => 'draft', 'usage' => ['copied_from_version' => $from->version], 'validation' => $from->validation,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $edits
     */
    private function assertLanguageHolds(ResumeProfileSynthesis $synthesis, array $edits, string $id): void
    {
        $candidate = $synthesis->replicate();
        $candidate->edits = $edits;
        $candidate->setRelation('careerProfile', $synthesis->careerProfile);
        $problems = array_filter($this->problems($candidate, EffectiveProfile::resolve($candidate)), fn (string $p) => str_starts_with($p, $this->label($id)));

        if ($problems !== []) {
            throw ValidationException::withMessages(['text' => array_values($problems)]);
        }
    }

    /**
     * @param  array<string, mixed>  $effective
     * @return array<int, string>
     */
    private function problems(ResumeProfileSynthesis $synthesis, array $effective): array
    {
        return $this->validator->validate(EffectiveProfile::presentationForValidation($effective), $synthesis->content['inventory'], $synthesis->careerProfile, enforceCounts: false)['errors'];
    }

    private function label(string $id): string
    {
        return match (true) {
            str_starts_with($id, 'differentiator:') => 'differentiator '.(((int) substr($id, 15)) + 1).':',
            str_starts_with($id, 'note:') => 'project note '.substr($id, 5).':',
            default => $id.':',
        };
    }

    private function exists(ResumeProfileSynthesis $synthesis, string $id): bool
    {
        $effective = EffectiveProfile::resolve($synthesis);
        $all = [...$effective['projects'], ...$effective['accomplishments'], ...$effective['capabilities']['direct'], ...$effective['presentation']['differentiators']];

        return in_array($id, array_column($all, 'id'), true);
    }

    private function assertDraft(ResumeProfileSynthesis $synthesis): void
    {
        if ($synthesis->status !== 'draft') {
            throw ValidationException::withMessages(['profile' => "This version is {$synthesis->status}. Start a new version to change it."]);
        }
    }
}
