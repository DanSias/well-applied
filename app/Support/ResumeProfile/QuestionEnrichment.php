<?php

namespace App\Support\ResumeProfile;

use App\Models\ClarificationQuestion;
use App\Models\ResumeProfile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional phrasing pass over the interview's open questions: one batched call that rewrites the
 * deterministic question so it reads naturally and names the user's real projects, then is
 * validated and cached. The model never ranks, never decides what is asked and never sees Private
 * evidence (the input is the already-eligible question context).
 *
 * Cache key: a fingerprint of the prompt version, the career evidence revision, the question's
 * deterministic text and its references. Any change (new evidence, a new sample, a re-ranked
 * question) invalidates it automatically, and the page falls back to the deterministic wording
 * until the next build. A page load never calls a model.
 *
 * Every output is checked: it must be a question about the given topic, must not assert that the
 * user did anything, must not contain a number that was not in the input, and (for deepening) must
 * not be a plain "have you used X" question. A failed check, or a failed call, leaves the
 * deterministic text in place for that question.
 */
final class QuestionEnrichment
{
    public function __construct(private readonly QuestionWriter $writer) {}

    /**
     * @return array{attempted: int, enriched: int, cached: int, rejected: int, failed: bool, latency_ms: int, usage: array<string, mixed>|null, model: string|null}
     */
    public function enrich(ResumeProfile $profile, ?int $onlyQuestionId = null): array
    {
        $stats = ['attempted' => 0, 'enriched' => 0, 'cached' => 0, 'rejected' => 0, 'failed' => false, 'latency_ms' => 0, 'usage' => null, 'model' => null];
        $session = $profile->interviewSessions()->latest('id')->first();
        $pending = [];

        foreach (($session?->questions()->where('status', 'open')->get() ?? collect()) as $question) {
            if ($onlyQuestionId !== null && $question->id !== $onlyQuestionId) {
                continue;
            }

            if (($question->context['enrichment']['fingerprint'] ?? null) === $this->fingerprint($profile, $question)) {
                $stats['cached']++;

                continue;
            }

            $pending[$question->id] = $question;
        }

        if ($pending === []) {
            return $stats;
        }

        $stats['attempted'] = count($pending);
        $input = array_values(array_map(fn (ClarificationQuestion $q) => $this->item($q), $pending));
        $started = microtime(true);

        try {
            $response = $this->writer->write($this->system(), $this->user($profile, $input), $this->schema(array_keys($pending)));
        } catch (Throwable $e) {
            Log::warning('Profile question enrichment failed; keeping deterministic questions.', ['exception' => $e::class]);
            $stats['failed'] = true;
            $stats['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

            return $stats;
        }

        $stats['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
        $stats['usage'] = $response['usage'];
        $stats['model'] = $response['model'];
        $texts = [];

        foreach ((array) ($response['content']['questions'] ?? []) as $row) {
            if (is_array($row) && isset($row['id'], $row['text']) && isset($pending[(int) $row['id']])) {
                $texts[(int) $row['id']] = trim((string) $row['text']);
            }
        }

        foreach ($pending as $id => $question) {
            $problem = isset($texts[$id]) ? $this->problem($texts[$id], $question) : 'missing from the response';

            if ($problem !== null) {
                $stats['rejected']++;

                continue;
            }

            $context = $question->context;
            $context['enrichment'] = [
                'text' => $texts[$id], 'fingerprint' => $this->fingerprint($profile, $question), 'model' => $response['model'],
                'prompt_version' => config('resume_profiles.enrichment.prompt_version'), 'latency_ms' => $stats['latency_ms'], 'batch_usage' => $response['usage'],
                'at' => now()->toIso8601String(),
            ];
            $question->update(['context' => $context]);
            $stats['enriched']++;
        }

        return $stats;
    }

    /**
     * The wording to show: the enriched text while it is still current, else the deterministic one.
     */
    public function display(ResumeProfile $profile, ClarificationQuestion $question): string
    {
        $e = $question->context['enrichment'] ?? null;

        return is_array($e) && ($e['fingerprint'] ?? null) === $this->fingerprint($profile, $question) ? (string) $e['text'] : $question->question_text;
    }

    public function fingerprint(ResumeProfile $profile, ClarificationQuestion $question): string
    {
        return hash('sha256', json_encode([
            config('resume_profiles.enrichment.prompt_version'), $profile->evidence_revision, $profile->market_sample_id,
            $question->question_text, $question->context['refs'] ?? [], $question->context['kind'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ClarificationQuestion $q): array
    {
        $c = $q->context;

        return [
            'id' => $q->id,
            'kind' => $c['kind'] ?? 'discovery',
            'capability' => $q->topic_label,
            'market' => $c['market'] ?? null,
            'known_projects_and_roles' => array_column((array) ($c['refs'] ?? []), 'label'),
            'known_facts' => array_map(fn (array $f) => mb_strimwidth((string) $f['statement'], 0, 160, '...'), array_slice((array) ($c['related_evidence'] ?? []), 0, 3)),
            'deterministic_question' => $q->question_text,
        ];
    }

    private function system(): string
    {
        return <<<'PROMPT'
        You rewrite interview questions for a career-discovery tool. Each question asks the user about their real professional experience so that a resume can later state it accurately.

        Rules:
        - Rewrite only the wording of each deterministic_question. Keep its meaning, its numbers and its target capability.
        - Write one question of at most three sentences. It must end with a question mark.
        - For kind "deepening" the user already has this capability. Do not ask whether they have used it. Ask which application involved the most substantial work, what they personally designed or implemented, which problems they solved, and whether there is a result they can quantify. Name the known projects or roles when given.
        - For kind "discovery" the record does not cover the capability. Ask neutrally whether they have done it and what they personally did. Never assume they have.
        - Use only the numbers, project names, role names and facts provided. Never invent a project, employer, technology, metric or outcome.
        - Never state or imply that the user did something. Questions only.
        - Plain, warm, professional language. No lists, no markdown.
        PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $input
     */
    private function user(ResumeProfile $profile, array $input): string
    {
        return "Role family: {$profile->name}\n\nQuestions to rewrite (JSON):\n".json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    private function schema(array $ids): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['questions'],
            'properties' => ['questions' => [
                'type' => 'array', 'minItems' => count($ids), 'maxItems' => count($ids),
                'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'text'], 'properties' => [
                    'id' => ['type' => 'integer', 'enum' => array_values($ids)],
                    'text' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 480],
                ]],
            ]],
        ];
    }

    private function problem(string $text, ClarificationQuestion $question): ?string
    {
        $c = $question->context;
        $length = mb_strlen($text);

        if ($length < 40 || $length > 480) {
            return 'length';
        }

        if (! str_ends_with($text, '?')) {
            return 'not a question';
        }

        // Numbers must come from the input (market counts and percentages, or the deterministic text).
        preg_match_all('/\d+(?:\.\d+)?/', $question->question_text.' '.json_encode($c['market'] ?? []).' '.json_encode($c['related_evidence'] ?? []), $allowed);
        preg_match_all('/\d+(?:\.\d+)?/', $text, $used);

        if (array_diff($used[0], $allowed[0]) !== []) {
            return 'invented number';
        }

        if (preg_match('/\b(?:you (?:built|implemented|designed|led|owned|created|developed|wrote|shipped|migrated)|you(?:\'ve| have) (?:built|implemented|led|owned|shipped))\b/i', $text) === 1) {
            return 'asserts experience';
        }

        if (($c['kind'] ?? null) === 'deepening' && preg_match('/^(?:have you|do you|are you|did you)\b/i', $text) === 1) {
            return 'yes/no question for a deepening topic';
        }

        $label = mb_strtolower((string) $question->topic_label);
        $words = array_filter(preg_split('/[^\p{L}\p{N}#+.]+/u', $label) ?: [], fn (string $w) => mb_strlen($w) > 3);

        if ($words !== [] && array_filter($words, fn (string $w) => str_contains(mb_strtolower($text), $w)) === []) {
            return 'off topic';
        }

        return null;
    }
}
