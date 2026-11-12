<?php

namespace App\Support\ResumeVariant;

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeEmptyOutputException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\ResumeDocument\ResumeText;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV2;

/**
 * The one narrow correction for a summary that validation cut down.
 *
 * RecoverWording is deterministic and only ever removes: when a summary sentence names a technology or a figure its
 * evidence does not state, the sentence goes, and what is left can be a single generic line. This step runs only then.
 * It asks for the summary alone, once, giving the model exactly what it may draw on (the summary's own evidence and
 * authorized technologies) and exactly what was rejected, and judges the answer by every rule a summary is already
 * judged by. It never re-asks for the resume, never retries, and never loosens a check.
 *
 * If the correction is unavailable, fails, or is no better, the recovered summary stays. A summary that is still
 * collapsed (shorter than `collapsed_below` words, the length under which it cannot position anyone) is then marked for
 * review in the report, so it is never mistaken for a finished one.
 */
final class CorrectSummary
{
    public function __construct(
        private readonly ResumeWordingResponseValidator $validator,
        private readonly RecoverWording $recovery,
    ) {}

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @param  array{content: array<string, mixed>, items: array<int, array<string, mixed>>}  $recovered  RecoverWording::recover()
     * @param  array<string, mixed>  $wordingInput  the input the wording call was given
     * @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>, outcome: string}
     */
    public function apply(array $recovered, GeneratesResumeWording $provider, ProfileJobWordingPromptV2 $prompt, array $wordingInput, WordingCheckContext $context, GenerationMetrics $metrics): array
    {
        $content = $recovered['content'];
        $items = $recovered['items'];
        $index = $this->summaryIndex($items);

        if ($index === null) {
            return [...$recovered, 'outcome' => 'no_summary'];
        }

        $cfg = (array) config('resume.composition.summary');
        $before = self::words((string) ($content['summary'] ?? ''));
        $cut = in_array($items[$index]['status'], ['generalized', 'omitted'], true);
        $outcome = 'not_needed';

        if ($cut && $before < (int) $cfg['min_words']) {
            $outcome = $this->correct($content, $items[$index], $provider, $prompt, $wordingInput, $context, $metrics);
        }

        $final = (string) ($content['summary'] ?? '');
        $after = self::words($final);

        if ($outcome === 'corrected') {
            $items[$index] = [...$items[$index], 'status' => 'generalized', 'text' => $final, 'action' => 'Rewritten once from the summary\'s eligible evidence after validation removed unsupported content.'];
        }

        if ($after < (int) $cfg['collapsed_below']) {
            $items[$index]['status'] = 'review';
            $items[$index]['text'] = $final;
            $items[$index]['findings'][] = [
                'kind' => 'summary_collapsed', 'severity' => 'review', 'detail' => (string) $after,
                'message' => $after === 0
                    ? 'The summary could not be written from eligible evidence and is empty. Write one before using this resume.'
                    : "The summary is only {$after} words after unsupported content was removed. It is too short to position the candidate; rewrite it before using this resume.",
            ];
        }

        $metrics->event(['stage' => 'wording', 'kind' => 'summary_recovery', 'outcome' => $outcome, 'words_before' => $before, 'words_after' => $after]);

        return ['content' => $content, 'items' => $items, 'outcome' => $outcome];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $wordingInput
     */
    private function correct(array &$content, array $item, GeneratesResumeWording $provider, ProfileJobWordingPromptV2 $prompt, array $wordingInput, WordingCheckContext $context, GenerationMetrics $metrics): string
    {
        $problems = array_values(array_unique(array_map(
            fn (array $f) => (string) $f['message'],
            array_filter((array) $item['findings'], fn (array $f) => $f['severity'] === 'block'),
        )));

        $input = [
            'target' => [
                'identity' => $wordingInput['reference_context']['target_identity'] ?? null,
                'role' => $wordingInput['job_context']['role'] ?? null,
                'lead_with' => $wordingInput['job_context']['lead_with'] ?? [],
            ],
            'summary_evidence' => array_map(fn (array $f) => array_filter([
                'key' => $f['key'] ?? null, 'statement' => $f['statement'] ?? null, 'project' => $f['attribution']['project'] ?? null,
                'support_only' => $f['support_only'] ?? null,
            ], fn ($v) => $v !== null), (array) ($wordingInput['summary_evidence'] ?? [])),
            'summary_authorized_skills' => $wordingInput['summary_authorized_skills'] ?? [],
            'not_supported' => $wordingInput['job_context']['not_supported'] ?? [],
            'problems' => $problems,
            'surviving_text' => (string) ($content['summary'] ?? ''),
        ];

        try {
            $response = $metrics->measure('wording', fn () => $provider->generate($prompt->correctionSystemPrompt(), $prompt->correctionUserPrompt($input), $prompt->correctionSchema()), fn ($response) => $response->usage);
        } catch (ResumeEmptyOutputException|ResumeGenerationProviderException) {
            return 'unavailable';
        }

        $candidate = ResumeText::clean(trim((string) ($response->structuredContent['summary'] ?? '')));

        if ($candidate === '') {
            return 'rejected';
        }

        $findings = $this->validator->textFindings('summary', $candidate, 'summary', $context, targetTerms: true, wordCount: false);

        if (array_filter($findings, fn (array $f) => $f['severity'] === 'block') !== []) {
            // The same sentence-level recovery every summary gets: keep only what is supported.
            [$candidate] = $this->recovery->recoverSummary($candidate, $findings, $context);
        }

        if ($candidate === '' || self::words($candidate) <= self::words((string) ($content['summary'] ?? ''))) {
            return 'rejected';
        }

        $content['summary'] = $candidate;

        return 'corrected';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function summaryIndex(array $items): ?int
    {
        foreach ($items as $i => $item) {
            if (($item['location'] ?? null) === 'summary') {
                return $i;
            }
        }

        return null;
    }
}
