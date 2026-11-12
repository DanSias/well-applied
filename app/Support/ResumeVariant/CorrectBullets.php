<?php

namespace App\Support\ResumeVariant;

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeEmptyOutputException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\ResumeDocument\ResumeText;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV4;

/**
 * The narrow correction for a bullet that left out a fact it cites.
 *
 * A valid response whose Nexus bullet cited a system-design fact and never said it used to cost a whole new resume (a
 * 13k-token reasoning pass) to fix one sentence. This rewrites just that bullet: it is given the bullet's own facts and
 * what is missing, once, and its answer is judged by every rule a bullet is judged by. The rest of the resume, and every
 * bullet's role, group and fact keys, are untouched, so multi-fact provenance cannot change.
 *
 * It is accepted only when the new text has no blocking finding AND states every fact the bullet cites. Otherwise (the call
 * is unavailable, fails, or is no better) the original stays and the missing fact is reported for review. It never loops,
 * never retries, never loosens a check, and is capped per run (`composition.retention.max_bullet_corrections`).
 */
final class CorrectBullets
{
    public function __construct(private readonly ResumeWordingResponseValidator $validator) {}

    /**
     * @param  array{content: array<string, mixed>, items: array<int, array<string, mixed>>}  $recovered  RecoverWording::recover()
     * @param  array<string, mixed>  $wordingInput  the input the wording call was given
     * @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>, resolved: array<int, string>}
     */
    public function apply(array $recovered, GeneratesResumeWording $provider, ProfileJobWordingPromptV4 $prompt, array $wordingInput, WordingCheckContext $context, GenerationMetrics $metrics): array
    {
        $content = $recovered['content'];
        $items = $recovered['items'];
        $resolved = [];
        $budget = (int) config('resume.composition.retention.max_bullet_corrections', 2);

        foreach ((array) ($content['experience'] ?? []) as $r => $role) {
            foreach ((array) $role['bullets'] as $b => $bullet) {
                $location = "{$role['role_id']}:{$bullet['bullet_group_index']}";
                $keys = $context->careerFactKeysByLocation[$location] ?? [];
                $missing = $this->validator->unstatedFacts((string) $bullet['text'], $keys, $context);

                if ($missing === []) {
                    continue;
                }

                if ($budget <= 0) {
                    $metrics->event(['stage' => 'wording', 'kind' => 'bullet_correction', 'location' => $location, 'outcome' => 'over_budget']);

                    continue;
                }

                $budget--;
                $text = $this->correct($bullet, $location, $keys, $missing, $role['role_id'], $provider, $prompt, $wordingInput, $context, $metrics);

                if ($text === null) {
                    continue;
                }

                $content['experience'][$r]['bullets'][$b]['text'] = $text;
                array_push($resolved, ...$missing);
                $this->recordItem($items, $role['role_id'], $bullet['bullet_group_index'], (string) $bullet['text'], $text, $missing);
            }
        }

        return ['content' => $content, 'items' => $items, 'resolved' => $resolved];
    }

    /**
     * @param  array<string, mixed>  $bullet
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $missing
     * @param  array<string, mixed>  $wordingInput
     */
    private function correct(array $bullet, string $location, array $keys, array $missing, int $roleId, GeneratesResumeWording $provider, ProfileJobWordingPromptV4 $prompt, array $wordingInput, WordingCheckContext $context, GenerationMetrics $metrics): ?string
    {
        $group = $this->group($wordingInput, $roleId, (int) $bullet['bullet_group_index']);
        $facts = array_map(fn (array $f) => SlimWordingFacts::fact($f), array_values(array_filter((array) ($group['career_facts'] ?? []))));

        if ($facts === []) {
            $metrics->event(['stage' => 'wording', 'kind' => 'bullet_correction', 'location' => $location, 'outcome' => 'no_facts']);

            return null;
        }

        $input = [
            'target' => ['role' => $wordingInput['job_context']['role'] ?? null, 'lead_with' => $wordingInput['job_context']['lead_with'] ?? []],
            'current_text' => (string) $bullet['text'],
            'facts' => $facts,
            'skills' => array_values(array_unique(array_merge(...array_map(fn (array $f) => (array) ($f['skills'] ?? []), $facts)))),
            'missing' => array_values(array_map(fn (string $k) => collect($facts)->firstWhere('key', $k)['statement'] ?? $k, $missing)),
        ];

        try {
            $response = $metrics->measure('wording', fn () => $provider->generate($prompt->bulletCorrectionSystemPrompt(), $prompt->bulletCorrectionUserPrompt($input), $prompt->bulletCorrectionSchema()), fn ($response) => $response->usage);
        } catch (ResumeEmptyOutputException|ResumeGenerationProviderException) {
            $metrics->event(['stage' => 'wording', 'kind' => 'bullet_correction', 'location' => $location, 'outcome' => 'unavailable']);

            return null;
        }

        $candidate = ResumeText::clean(trim((string) ($response->structuredContent['text'] ?? '')));
        $outcome = 'corrected';

        if ($candidate === '') {
            $outcome = 'rejected';
        } elseif (array_filter($this->validator->textFindings('bullet', $candidate, $location, $context, targetTerms: true, wordCount: true), fn (array $f) => $f['severity'] === 'block') !== []) {
            $outcome = 'rejected';
        } elseif ($this->validator->unstatedFacts($candidate, $keys, $context) !== []) {
            $outcome = 'rejected';
        }

        $metrics->event(['stage' => 'wording', 'kind' => 'bullet_correction', 'location' => $location, 'outcome' => $outcome]);

        return $outcome === 'corrected' ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $wordingInput
     * @return array<string, mixed>
     */
    private function group(array $wordingInput, int $roleId, int $index): array
    {
        foreach ((array) ($wordingInput['experience'] ?? []) as $role) {
            if (($role['role_id'] ?? null) !== $roleId) {
                continue;
            }

            foreach ((array) $role['bullet_groups'] as $group) {
                if (($group['bullet_group_index'] ?? null) === $index) {
                    return $group;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $missing
     */
    private function recordItem(array &$items, int $roleId, int $index, string $before, string $after, array $missing): void
    {
        foreach ($items as $i => $item) {
            if (($item['location'] ?? null) === 'experience' && ($item['role_id'] ?? null) === $roleId && ($item['bullet_group_index'] ?? null) === $index) {
                $items[$i] = [...$item, 'status' => 'generalized', 'text' => $after, 'original' => $item['original'] ?? $before,
                    'action' => trim(($item['action'] ?? '').' Rewritten once from the bullet\'s own facts because it left out ['.implode(', ', $missing).'].')];

                return;
            }
        }
    }
}
