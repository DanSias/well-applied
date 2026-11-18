<?php

namespace App\Support\ApplicationPreparation;

use App\Models\AgentRun;

/**
 * The review view of one finished preparation attempt, built from its private report and shaped for a person reading it:
 * a concise summary plus the lists behind it, not raw browser controls.
 *
 * Minimised on purpose. It carries labels, kinds and statuses, never the values the worker typed or read back
 * (`expected` / `actual`), never an option list or selected value, and never a filesystem path: the screenshots are named
 * and fetched through a scoped route. The questions are the report's logical questions, so a checkbox group is one entry.
 * A question the page gave no label stays unlabelled (`label: null`), never borrowed from an option.
 *
 * `submission.occurred` is always false: nothing in this slice can submit, and nothing here is read from a worker's claim.
 */
final class PresentPreparationReview
{
    public function __construct(private readonly PreparationArtifacts $artifacts) {}

    /**
     * @return array<string, mixed>
     */
    public function present(AgentRun $run): array
    {
        $report = $this->artifacts->readReport($run);
        $in = $run->input_summary ?? [];
        $summary = $run->result_summary ?? [];

        $base = [
            'agent_run_id' => $run->id,
            'status' => $run->status->value,
            'submission' => [
                'occurred' => false,
                'statement' => 'Nothing was submitted. This is a prepared form for you to review; sending it is a separate step that has not happened.',
            ],
            'resume' => [
                'variant_id' => $in['resume_variant_id'] ?? null,
                'sha256' => $in['resume_sha256'] ?? null,
                'bytes' => $in['resume_bytes'] ?? null,
            ],
        ];

        if ($report === null) {
            return $base + ['available' => false, 'failure_category' => $run->failure_category?->value, 'failure_message' => $run->failure_message];
        }

        $questions = array_map(fn (array $q) => [
            'label' => isset($q['label']) && trim((string) $q['label']) !== '' && ($q['labelSource'] ?? null) !== 'unresolved' ? (string) $q['label'] : null,
            'required' => $q['required'] ?? null,
            'kind' => $q['kind'] ?? null,
            'status' => $q['status'] ?? null,
            'review' => $q['review'] ?? null,
            'sensitive' => (bool) ($q['sensitive'] ?? false),
        ], $report['questions'] ?? []);

        $by = fn (string ...$reviews) => array_values(array_filter($questions, fn (array $q) => in_array($q['review'], $reviews, true)));

        $upload = $report['resume']['upload'] ?? null;

        return [
            ...$base,
            'available' => true,
            'outcome' => $report['outcome'],
            'reported_outcome' => $report['reported_outcome'] ?? $report['outcome'],
            'reviewable' => (bool) ($summary['reviewable'] ?? false),
            'integrity_flags' => $report['integrity_flags'] ?? [],
            'ats' => $report['ats'] ?? null,
            'reasons' => $report['reasons'] ?? [],
            'warnings' => $report['warnings'] ?? [],
            'counts' => $summary['counts'] ?? [],
            'question_summary' => $report['question_summary'] ?? [],
            'resume' => [
                ...$base['resume'],
                'verified' => (bool) ($report['resume']['verified'] ?? false),
                'verification' => $report['resume']['verification'] ?? null,
                'reported_sha256_matches' => ($report['resume']['sha256'] ?? null) === ($in['resume_sha256'] ?? null),
                'upload' => $upload === null ? null : [
                    'outcome' => $upload['outcome'] ?? null,
                    'http_status' => $upload['response']['status'] ?? null,
                    'filename_visible' => $upload['filenameVisible'] ?? null,
                    'detail' => $upload['detail'] ?? null,
                ],
            ],
            'verified' => $by('complete'),
            'needs_answer' => $by('needs_answer'),
            'optional_skipped' => $by('optional_skipped'),
            'needs_person' => $by('needs_person'),
            'needs_check' => $by('needs_check'),
            'failed' => $by('failed'),
            'safety_events' => array_map(fn (array $e) => [
                'kind' => $e['kind'],
                'detail' => $e['detail'],
                'failure' => in_array($e['kind'], SanitizePreparationReport::FAILURE_KINDS, true),
            ], $report['safety_events'] ?? []),
            'blockers' => $report['blockers'] ?? [],
            'screenshots' => array_values(array_map(fn (array $s) => [
                'file' => $s['file'],
                'phase' => $s['phase'] ?? null,
                'index' => $s['index'] ?? null,
                'of' => $s['of'] ?? null,
                'stored' => (bool) ($s['stored'] ?? false),
                'url' => ($s['stored'] ?? false) ? route('preparation-attempts.screenshots.show', ['agentRun' => $run->id, 'name' => $s['file']], false) : null,
            ], $report['screenshots'] ?? [])),
        ];
    }
}
