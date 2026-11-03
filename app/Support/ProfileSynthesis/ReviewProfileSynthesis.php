<?php

namespace App\Support\ProfileSynthesis;

use App\Models\ResumeProfileSynthesis;
use App\Support\ResumeProfile\CapabilityCoverage;
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

    private const KEY_IDS = '/^(?:summary|differentiator:\d+|note:\d+)$/';

    private const ITEM_IDS = '/^(?:project:\d+|capability:[a-z0-9_]+|accomplishment:[a-z0-9._-]+|differentiator:\d+)$/';

    public function __construct(private readonly ProfilePresentationValidator $validator, private readonly CapabilityCoverage $coverage) {}

    /**
     * @param  array{include?: bool, pinned?: bool, order?: int|null, text?: string, fact_keys?: array<int, string>}  $fields  `fact_keys` replaces the facts a piece of language cites (summary, differentiators and project notes); it is judged with the wording in one step
     */
    public function edit(ResumeProfileSynthesis $synthesis, string $id, array $fields): ResumeProfileSynthesis
    {
        $this->assertDraft($synthesis);
        $edits = $synthesis->edits ?? ['items' => [], 'text' => []];

        $touched = false;

        if (array_key_exists('text', $fields)) {
            if (preg_match(self::TEXT_IDS, $id) !== 1) {
                throw ValidationException::withMessages(['text' => 'That item has no wording to edit.']);
            }

            $edits['text'][$id] = trim((string) preg_replace('/\s+/u', ' ', (string) $fields['text']));
            $touched = true;
        }

        if (array_key_exists('fact_keys', $fields)) {
            if (preg_match(self::KEY_IDS, $id) !== 1) {
                throw ValidationException::withMessages(['fact_keys' => 'That item does not cite facts.']);
            }

            $keys = array_values(array_unique(array_map('strval', (array) $fields['fact_keys'])));
            $edits['keys'][$id] = $this->citeable($synthesis, $edits, $id, $keys);
            $touched = true;
        }

        if ($touched) {
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
     * The facts a piece of language may cite after the user's change. Every key must be an eligible fact in this
     * profile's inventory, at least one must remain, and a fact the user ADDS must actually support what the
     * wording says (it states a capability the wording names, or shares its specifics). Anything else is unrelated
     * evidence and is refused.
     *
     * @param  array<string, mixed>  $edits
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function citeable(ResumeProfileSynthesis $synthesis, array $edits, string $id, array $keys): array
    {
        $facts = $synthesis->content['inventory']['facts'];

        if ($keys === []) {
            throw ValidationException::withMessages(['fact_keys' => 'Cite at least one fact.']);
        }

        $candidate = $synthesis->replicate();
        $candidate->edits = $edits;
        $candidate->setRelation('careerProfile', $synthesis->careerProfile);
        $effective = EffectiveProfile::resolve($candidate);
        $text = match (true) {
            $id === 'summary' => $effective['presentation']['summary'],
            str_starts_with($id, 'differentiator:') => $this->row($effective['presentation']['differentiators'], $id)['statement'] ?? '',
            default => $this->row($effective['presentation']['project_notes'], $id)['note'] ?? '',
        };
        $current = $id === 'summary'
            ? $effective['presentation']['summary_fact_keys']
            : ($this->row([...$effective['presentation']['differentiators'], ...$effective['presentation']['project_notes']], $id)['fact_keys'] ?? []);

        foreach ($keys as $key) {
            if (! isset($facts[$key])) {
                throw ValidationException::withMessages(['fact_keys' => "[{$key}] is not an eligible fact in this profile."]);
            }

            if (! in_array($key, $current, true) && ! $this->supports($facts[$key], (string) $text)) {
                throw ValidationException::withMessages(['fact_keys' => "[{$key}] does not support anything this wording says, so it was not added. Cite a fact that states what the text claims."]);
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function row(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fact  an inventory fact
     */
    private function supports(array $fact, string $text): bool
    {
        if (array_intersect($this->coverage->capabilitiesIn($text), $fact['capabilities']) !== []) {
            return true;
        }

        $words = fn (string $t) => array_unique(array_filter(preg_split('/[^a-z0-9]+/', strtolower($t)) ?: [], fn (string $w) => strlen($w) >= 5));

        return count(array_intersect($words($text), $words((string) $fact['statement']))) >= 2;
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
