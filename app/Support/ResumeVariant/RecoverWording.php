<?php

namespace App\Support\ResumeVariant;

use App\Exceptions\InvalidResumeVariantResponseException;

/**
 * Deterministic, bullet-level recovery for a Wording response that is
 * structurally sound but has individual locations that must not ship.
 * No model call is ever made here.
 *
 * For each location (summary, experience bullet, project highlight):
 *  - no findings: `passed`, kept as written;
 *  - only review findings: `review`, kept as written and flagged;
 *  - a block finding: first try to GENERALIZE, using only edits that
 *    cannot add a claim: deleting a Restricted-only figure that sits in
 *    front of a plain noun ("updated 32,000 merchants" becomes
 *    "updated merchants"), or replacing a confidential term with the
 *    phrase config/resume.php `generalizations` lists for it. The
 *    rewrite is re-checked with the same rules and kept (as
 *    `generalized`, flagged for review) only if no block finding
 *    remains. Otherwise the location is `omitted`; a summary instead
 *    drops just the offending sentences.
 *
 * Finally every surviving location is re-checked and the whole
 * response is refused if anything blocked is still present, so recovery
 * can never be the way blocked text reaches a variant.
 */
final class RecoverWording
{
    /** A figure after one of these words is a measure ("by 32,000 records"); deleting it would break the sentence. */
    private const MEASURE_PREPOSITIONS = ['by', 'to', 'from', 'of', 'at', 'than', 'for', 'in', 'on', 'with', 'across', 'under', 'below', 'exceeding', 'about', 'around', 'near', 'against', 'per'];

    private const QUANTIFIER = '(?:(?:over|more than|about|approximately|nearly|around|almost)\s+)?';

    public function __construct(private readonly ResumeWordingResponseValidator $validator) {}

    /**
     * @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>}
     *
     * @throws InvalidResumeVariantResponseException
     */
    public function recover(WordingTriage $triage, WordingCheckContext $context): array
    {
        /** @var array<string, mixed> $content */
        $content = $triage->validated ?? [];
        $items = [];

        // ---- Summary ----
        $summary = (string) ($content['summary'] ?? '');
        $summaryFindings = $triage->findings['summary'] ?? [];
        $summaryItem = ['location' => 'summary', 'field' => 'summary', 'fact_keys' => $context->careerFactKeysByLocation['summary'] ?? []];

        if ($this->blockedIn($summaryFindings)) {
            [$summary, $status, $action] = $this->recoverSummary($summary, $summaryFindings, $context);
            $items[] = [...$summaryItem, 'status' => $status, 'findings' => $summaryFindings, 'action' => $action, 'text' => $summary];
            $content['summary'] = $summary;
        } else {
            $items[] = [...$summaryItem, 'status' => $summaryFindings === [] ? 'passed' : 'review', 'findings' => $summaryFindings, 'action' => null, 'text' => $summary];
        }

        // ---- Experience bullets ----
        foreach ((array) ($content['experience'] ?? []) as $roleIndex => $role) {
            $kept = [];

            foreach ((array) $role['bullets'] as $bulletIndex => $bullet) {
                $field = "experience.{$roleIndex}.bullets.{$bulletIndex}.text";
                $locationKey = "{$role['role_id']}:{$bullet['bullet_group_index']}";
                $findings = $triage->findings[$field] ?? [];
                $item = [
                    'location' => 'experience', 'field' => $field, 'role_id' => $role['role_id'],
                    'bullet_group_index' => $bullet['bullet_group_index'],
                    'fact_keys' => $context->careerFactKeysByLocation[$locationKey] ?? [],
                ];

                if (! $this->blockedIn($findings)) {
                    $items[] = [...$item, 'status' => $findings === [] ? 'passed' : 'review', 'findings' => $findings, 'action' => null, 'text' => $bullet['text']];
                    $kept[] = $bullet;

                    continue;
                }

                $rewrite = $this->generalize($field, $bullet['text'], $locationKey, $findings, $context, targetTerms: true, wordCount: true);

                if ($rewrite === null) {
                    $items[] = [...$item, 'status' => 'omitted', 'findings' => $findings, 'action' => 'Removed: it could not be generalized from eligible evidence.', 'text' => null];

                    continue;
                }

                $items[] = [...$item, 'status' => 'generalized', 'findings' => $findings, 'action' => $rewrite['action'], 'text' => $rewrite['text']];
                $kept[] = [...$bullet, 'text' => $rewrite['text']];
            }

            $content['experience'][$roleIndex]['bullets'] = $kept;
        }

        // ---- Project highlights ----
        $keptProjects = [];

        foreach ((array) ($content['selected_projects'] ?? []) as $projectIndex => $project) {
            $field = "selected_projects.{$projectIndex}.text";
            $locationKey = "project:{$project['project_id']}";
            $findings = $triage->findings[$field] ?? [];
            $item = ['location' => 'project', 'field' => $field, 'project_id' => $project['project_id'], 'fact_keys' => $context->careerFactKeysByLocation[$locationKey] ?? []];

            if (! $this->blockedIn($findings)) {
                $items[] = [...$item, 'status' => $findings === [] ? 'passed' : 'review', 'findings' => $findings, 'action' => null, 'text' => $project['text']];
                $keptProjects[] = $project;

                continue;
            }

            $rewrite = $this->generalize($field, $project['text'], $locationKey, $findings, $context, targetTerms: false, wordCount: true);

            if ($rewrite === null) {
                $items[] = [...$item, 'status' => 'omitted', 'findings' => $findings, 'action' => 'Removed: it could not be generalized from eligible evidence.', 'text' => null];

                continue;
            }

            $items[] = [...$item, 'status' => 'generalized', 'findings' => $findings, 'action' => $rewrite['action'], 'text' => $rewrite['text']];
            $keptProjects[] = [...$project, 'text' => $rewrite['text']];
        }

        $content['selected_projects'] = $keptProjects;

        $this->assertNothingBlockedRemains($content, $context);

        return ['content' => $content, 'items' => $items];
    }

    /**
     * @param  array<int, array{kind: string, severity: string, detail: string, message: string}>  $findings
     */
    private function blockedIn(array $findings): bool
    {
        return array_filter($findings, fn (array $f) => $f['severity'] === 'block') !== [];
    }

    /**
     * @param  array<int, array{kind: string, severity: string, detail: string, message: string}>  $findings
     * @return array{text: string, action: string}|null
     */
    private function generalize(string $field, string $text, string $locationKey, array $findings, WordingCheckContext $context, bool $targetTerms, bool $wordCount): ?array
    {
        $guard = new RestrictedEvidenceGuard;
        $rewritten = $text;
        $actions = [];

        foreach ($findings as $finding) {
            if ($finding['severity'] !== 'block') {
                continue;
            }

            if ($finding['kind'] === 'restricted_figure') {
                foreach ($guard->figureTokens($rewritten, $finding['detail']) as $token) {
                    $lead = '/(?<![\p{L}\p{N}])([\p{L}]+)\s+'.self::QUANTIFIER.preg_quote($token, '/').'/iu';

                    if (preg_match($lead, $rewritten, $before) === 1 && in_array(mb_strtolower($before[1]), self::MEASURE_PREPOSITIONS, true)) {
                        return null;
                    }

                    $pattern = '/'.self::QUANTIFIER.preg_quote($token, '/').'\s+(?=[\p{L}])/iu';
                    $next = preg_replace($pattern, '', $rewritten, 1);

                    if ($next === null || $next === $rewritten) {
                        return null; // The figure is not in front of a plain noun; removing it could break the sentence.
                    }

                    $rewritten = $next;
                }

                $actions[] = "removed the restricted figure [{$finding['detail']}]";
            } elseif ($finding['kind'] === 'confidential_term') {
                $replacement = $this->generalizationFor($finding['detail']);

                if ($replacement === null) {
                    return null;
                }

                $rewritten = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($finding['detail'], '/').'(?![\p{L}\p{N}])/iu', $replacement, $rewritten);
                $actions[] = "replaced [{$finding['detail']}] with \"{$replacement}\"";
            } else {
                return null; // Unsupported claims and the like are never rewritten.
            }
        }

        $rewritten = trim($rewritten);

        if ($rewritten === '' || str_word_count($rewritten) < 3) {
            return null;
        }

        $rewritten = mb_strtoupper(mb_substr($rewritten, 0, 1)).mb_substr($rewritten, 1);

        $recheck = $this->validator->textFindings($field, $rewritten, $locationKey, $context, $targetTerms, $wordCount);

        if ($this->blockedIn($recheck)) {
            return null;
        }

        return ['text' => $rewritten, 'action' => ucfirst(implode('; ', $actions)).'.'];
    }

    private function generalizationFor(string $term): ?string
    {
        foreach ((array) config('resume.generalizations', []) as $from => $to) {
            if (mb_strtolower((string) $from) === mb_strtolower($term) && is_string($to) && trim($to) !== '') {
                return $to;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{kind: string, severity: string, detail: string, message: string}>  $findings
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function recoverSummary(string $summary, array $findings, WordingCheckContext $context): array
    {
        $rewrite = $this->generalize('summary', $summary, 'summary', $findings, $context, targetTerms: true, wordCount: false);

        if ($rewrite !== null) {
            return [$rewrite['text'], 'generalized', $rewrite['action']];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($summary)) ?: [];
        $kept = array_values(array_filter($sentences, fn (string $sentence) => ! $this->blockedIn(
            $this->validator->textFindings('summary', $sentence, 'summary', $context, targetTerms: true, wordCount: false),
        )));

        if ($kept === [] || count($kept) === count($sentences)) {
            return ['', 'omitted', 'Removed: the summary could not be generalized from eligible evidence.'];
        }

        return [implode(' ', $kept), 'generalized', 'Removed '.(count($sentences) - count($kept)).' sentence(s) that could not be generalized.'];
    }

    /**
     * @param  array<string, mixed>  $content
     *
     * @throws InvalidResumeVariantResponseException
     */
    private function assertNothingBlockedRemains(array $content, WordingCheckContext $context): void
    {
        $problems = [];

        if (($content['summary'] ?? '') !== '' && $this->blockedIn($this->validator->textFindings('summary', $content['summary'], 'summary', $context, true, false))) {
            $problems[] = 'summary';
        }

        foreach ((array) $content['experience'] as $role) {
            foreach ((array) $role['bullets'] as $bullet) {
                if ($this->blockedIn($this->validator->textFindings('bullet', $bullet['text'], "{$role['role_id']}:{$bullet['bullet_group_index']}", $context, true, true))) {
                    $problems[] = "role {$role['role_id']} bullet {$bullet['bullet_group_index']}";
                }
            }
        }

        foreach ((array) $content['selected_projects'] as $project) {
            if ($this->blockedIn($this->validator->textFindings('project', $project['text'], "project:{$project['project_id']}", $context, false, true))) {
                $problems[] = "project {$project['project_id']}";
            }
        }

        if ($problems !== []) {
            throw new InvalidResumeVariantResponseException(
                'Resume Wording recovery left blocked content in: '.implode(', ', $problems).'.'
            );
        }
    }
}
