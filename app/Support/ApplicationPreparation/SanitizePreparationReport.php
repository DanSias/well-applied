<?php

namespace App\Support\ApplicationPreparation;

use App\Enums\PreparationOutcome;
use App\Models\AgentRun;

/**
 * Turns a validated worker report into what Well Applied keeps, and decides what it is allowed to mean.
 *
 * The worker's report is evidence, not authority. This keeps only the whitelisted fields (the request validation has
 * already dropped everything else), removes query strings from every URL-ish string, and then derives the outcome that
 * is stored, never trusting the worker's own verdict to make an attempt look better than its evidence:
 *
 * - any submission-like safety event, a suspected submission or a blocked attempt makes the outcome `safety_failure`,
 *   whatever the worker called it;
 * - `ready_for_review` is downgraded to `blocked` when the resume the worker reports is not the resume it was given,
 *   or when the worker itself did not verify the resume upload.
 *
 * Nothing here can produce, or be read as, a submission.
 */
final class SanitizePreparationReport
{
    /** The safety event kinds that mean something looked like submission or an unexpected side effect. */
    /**
     * Integrity flags that make evidence untrustworthy (the wrong resume, a screenshot that is not what the worker
     * declared). A merely missing screenshot is noted but does not, on its own, stop a person reviewing the rest.
     */
    public const UNTRUSTWORTHY_FLAGS = ['resume_sha256_mismatch', 'screenshot_hash_mismatch'];

    public const FAILURE_KINDS = ['blocked_mutating_request', 'submit_event_blocked', 'unexpected_navigation', 'suspected_submission'];

    /**
     * @param  array<string, mixed>  $report  the validated `report` payload
     * @param  array<int, array{file: string, sha256: string}>  $manifest  the validated screenshot manifest
     * @return array{report: array<string, mixed>, summary: array<string, mixed>, outcome: PreparationOutcome}
     */
    public function project(AgentRun $run, array $report, array $manifest, PreparationArtifacts $artifacts): array
    {
        $flags = [];

        $events = array_map(fn (array $e) => [
            'kind' => $e['kind'],
            'detail' => $this->withoutQueries((string) ($e['detail'] ?? '')),
            'phase' => $e['phase'] ?? 'preparation',
            'at' => $e['at'] ?? null,
        ], $report['safetyEvents'] ?? []);

        $failureEvents = array_values(array_filter($events, fn (array $e) => in_array($e['kind'], self::FAILURE_KINDS, true)));
        $submission = $report['submission'];
        $reported = PreparationOutcome::from($report['outcome']);

        $outcome = $reported;
        if ($failureEvents !== [] || ($submission['suspected'] ?? false) === true || ($submission['blockedAttempts'] ?? 0) > 0) {
            $outcome = PreparationOutcome::SafetyFailure;
        }

        $resume = $report['resume'] ?? [];
        $expectedSha = $run->input_summary['resume_sha256'] ?? null;
        if (isset($resume['sha256']) && $expectedSha !== null && ! hash_equals($expectedSha, $resume['sha256'])) {
            $flags[] = 'resume_sha256_mismatch';
        }
        $untrustworthy = array_intersect($flags, self::UNTRUSTWORTHY_FLAGS) !== [];
        if ($outcome === PreparationOutcome::ReadyForReview && ($untrustworthy || ($resume['verified'] ?? false) !== true)) {
            $outcome = PreparationOutcome::Blocked;
        }

        $stored = 0;
        $shots = [];
        $byFile = collect($manifest)->keyBy('file');
        foreach ($report['screenshots'] ?? [] as $shot) {
            $bytes = $artifacts->screenshot($run, $shot['file']);
            $declared = $byFile->get($shot['file'])['sha256'] ?? null;
            $present = $bytes !== null;
            if ($present && $declared !== null && ! hash_equals($declared, hash('sha256', $bytes))) {
                $present = false;
                $flags[] = 'screenshot_hash_mismatch';
            }
            $stored += $present ? 1 : 0;
            $shots[] = [...$shot, 'stored' => $present];
        }
        if ($stored < count($shots)) {
            $flags[] = 'screenshots_missing';
        }

        $flags = array_values(array_unique($flags));
        $untrustworthy = array_intersect($flags, self::UNTRUSTWORTHY_FLAGS) !== [];

        $projection = [
            'schema_version' => 1,
            'worker_report_schema_version' => $report['schemaVersion'],
            'ats' => $report['ats'],
            'requested_url' => $this->withoutQueries($report['requestedUrl'] ?? null),
            'final_url' => $this->withoutQueries($report['finalUrl'] ?? null),
            'reported_outcome' => $reported->value,
            'outcome' => $outcome->value,
            'integrity_flags' => $flags,
            'reasons' => array_map(fn ($r) => $this->withoutQueries($r), $report['reasons'] ?? []),
            'summary' => $report['summary'] ?? [],
            'question_summary' => $report['questionSummary'] ?? [],
            'questions' => array_map($this->redactQuestion(...), $report['questions'] ?? []),
            'fields' => array_map(fn (array $f) => ($f['sensitive'] ?? false) ? [...$f, 'expected' => null, 'actual' => null] : $f, $report['fields'] ?? []),
            'resume' => $resume,
            'blockers' => $report['blockers'] ?? [],
            'submission' => [
                'assertion' => 'worker_did_not_submit',
                'suspected' => (bool) ($submission['suspected'] ?? false),
                'blocked_attempts' => (int) ($submission['blockedAttempts'] ?? 0),
            ],
            'safety_events' => $events,
            'warnings' => array_map(fn ($w) => $this->withoutQueries($w), $report['warnings'] ?? []),
            'diagnostics' => $report['diagnostics'] ?? [],
            'screenshots' => $shots,
        ];

        $upload = $resume['upload'] ?? null;
        $summary = [
            'outcome' => $outcome->value,
            'reported_outcome' => $reported->value,
            'reviewable' => $outcome->reviewable() && ! $untrustworthy,
            'integrity_flags' => $flags,
            'ats' => $report['ats'],
            'counts' => $report['summary'] ?? [],
            'question_summary' => $report['questionSummary'] ?? [],
            'upload' => $upload === null ? null : [
                'outcome' => $upload['outcome'] ?? null,
                'http_status' => $upload['response']['status'] ?? null,
                'filename_visible' => $upload['filenameVisible'] ?? null,
                'verified' => (bool) ($resume['verified'] ?? false),
                'verification' => $resume['verification'] ?? null,
            ],
            'captcha' => $report['blockers']['captcha'] ?? null,
            'safety' => [
                'failure' => $outcome === PreparationOutcome::SafetyFailure,
                'event_counts' => array_count_values(array_column($events, 'kind')),
            ],
            'screenshots' => ['declared' => count($shots), 'stored' => $stored],
            'duration_ms' => $report['diagnostics']['durationMs'] ?? null,
            'delivered_at' => now()->toIso8601String(),
        ];

        return ['report' => $projection, 'summary' => $summary, 'outcome' => $outcome];
    }

    /** Labels that name a demographic, identity or credential topic: never carry a value for these, whatever the worker says. */
    private const SENSITIVE_LABEL = '/\b(gender|sex|race|racial|ethnic\w*|hispanic|latino|latina|veteran|military|disabilit\w*|sexual|orientation|religio\w*|pronouns?|transgender|marital|birth|ssn|social security|passport|password|passcode|token|secret|api key|credential\w*)\b/i';

    /**
     * What a review may keep of one logical question, re-checked here whatever the worker sent: a value survives only
     * where its provenance says where it came from, and never for a sensitive question (by the worker's own flag or by
     * its label). `entered` is only a verified, profile- or answer-sourced single control; `intended` only a mapped value
     * that was not entered (or the resume's file name); `page_selected` only what the page itself had selected.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private function redactQuestion(array $q): array
    {
        $sensitive = (bool) ($q['sensitive'] ?? false) || preg_match(self::SENSITIVE_LABEL, (string) ($q['label'] ?? '')) === 1;
        $source = $q['provenance']['source'] ?? null;
        $fromWorker = in_array($source, ['applicant_fact', 'approved_answer'], true);
        $kind = $q['kind'] ?? null;

        $q['sensitive'] = $sensitive;
        $q['entered'] = ! $sensitive && $fromWorker && ($q['status'] ?? null) === 'filled_verified' && ! in_array($kind, ['file', 'checkbox'], true)
            ? $this->clip($q['entered'] ?? null, 500) : null;
        $q['intended'] = ! $sensitive && ($fromWorker || $source === 'chosen_resume') && (($q['status'] ?? null) !== 'filled_verified' || $source === 'chosen_resume')
            ? $this->clip($q['intended'] ?? null, 500) : null;
        $q['page_selected'] = ! $sensitive && $source === 'none' && in_array($kind, ['select', 'radio', 'checkbox', 'text', 'textarea'], true)
            ? array_values(array_map(fn ($v) => (string) mb_substr((string) $v, 0, 200), array_slice((array) ($q['page_selected'] ?? []), 0, 20)))
            : [];
        $q['note'] = $sensitive ? null : $this->clip($this->withoutQueries($q['note'] ?? null), 300);

        return $q;
    }

    private function clip(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function withoutQueries(?string $text): ?string
    {
        // Reports keep origin and path only; this is a second line of defence for any URL a detail string carries.
        return $text === null ? null : preg_replace('/\?[^\s\]\)"\'<>]*/', '', $text);
    }
}
