<?php

namespace App\Support\ResumeRevision;

use App\Enums\GenerationType;
use App\Models\CareerFact;
use App\Models\JobPosting;
use App\Models\ResumeVariant;

/**
 * What the resume workspace needs beyond the resume itself: which resume this is among the job's, its history, the page
 * count, a compact edit log, and one "needs attention" list that merges the wording review with the composition checks.
 * A fact is named only when it is not Private.
 */
final class PresentResumeWorkspace
{
    private const EDIT_LABELS = [
        'summary_edited' => 'Edited the summary',
        'bullet_edited' => 'Edited a bullet',
        'bullet_replaced' => 'Replaced a bullet',
        'bullet_added' => 'Added a bullet',
        'bullet_removed' => 'Removed a bullet',
        'bullets_reordered' => 'Reordered bullets',
        'skill_removed' => 'Removed a skill',
        'summary_evidence_added' => 'Linked the summary to the project it names',
    ];

    public function __construct(private readonly JobResumes $jobResumes) {}

    /**
     * @return array<string, mixed>
     */
    public function present(JobPosting $posting, ResumeVariant $variant): array
    {
        $variant->loadMissing(['synthesis.resumeProfile']);
        $history = $this->jobResumes->history($posting);
        $self = collect($history)->firstWhere('id', $variant->id);
        $keys = $this->factKeys($variant);
        $facts = CareerFact::query()->whereIn('key', $keys)->where('visibility', '!=', 'private')->get()->keyBy('key');
        $describe = fn (string $key) => $facts->has($key) ? ['key' => $key, 'statement' => $facts[$key]->statement, 'visibility' => $facts[$key]->visibility->value] : null;
        $describeAll = fn (array $list) => array_values(array_filter(array_map($describe, $list)));
        $attention = [];

        foreach ((array) ($variant->review_report['items'] ?? []) as $item) {
            if ($item['status'] === 'passed') {
                continue;
            }

            $attention[] = [
                'source' => 'wording', 'severity' => 'review', 'status' => $item['status'], 'title' => $item['label'],
                'role_id' => $item['role_id'] ?? null, 'bullet_order' => isset($item['bullet_group_index']) ? $item['bullet_group_index'] + 1 : null,
                'messages' => array_values(array_unique(array_filter([...array_column($item['findings'], 'message'), $item['action']]))),
                'claim' => $item['text'] ?? null,
                'facts' => $describeAll(array_column($item['evidence'], 'fact_key')),
                'available' => [],
            ];
        }

        foreach ((array) ($variant->selection_input_snapshot['tailoring']['composition'] ?? []) as $finding) {
            $attention[] = [
                'source' => 'composition', 'severity' => $finding['severity'], 'status' => $finding['severity'], 'title' => match ($finding['check']) {
                    'engineering_evidence' => 'Engineering evidence',
                    'result_context' => 'Result without its context',
                    default => 'Claim strength',
                },
                'role_id' => null, 'bullet_order' => null, 'messages' => [$finding['message']], 'claim' => null,
                'facts' => $describeAll($finding['selected']), 'available' => $describeAll($finding['available']),
            ];
        }

        usort($attention, fn (array $a, array $b) => ($a['severity'] === 'informational') <=> ($b['severity'] === 'informational'));

        return [
            'variant' => [
                'id' => $variant->id,
                'kind' => $variant->parent_variant_id === null ? 'generated' : 'revision',
                'label' => $self['label'] ?? 'Tailored resume',
                'parent_id' => $variant->parent_variant_id,
                'generated_at' => $variant->generated_at->toIso8601String(),
                'current' => (bool) ($self['current'] ?? false),
                'preferred' => (bool) ($self['preferred'] ?? false),
                'pages' => $this->pages($variant),
                'profile' => $variant->synthesis === null ? null : ['name' => (string) $variant->synthesis->resumeProfile->name, 'version' => $variant->synthesis->version],
                'editable' => $variant->job_analysis_id !== null && $variant->resume_profile_synthesis_id !== null,
            ],
            'history' => $history,
            'attention' => $attention,
            'edit_log' => $this->editLog((array) ($variant->edit_log['edits'] ?? [])),
        ];
    }

    /**
     * The same kind of change twice reads as one line with a count.
     *
     * @param  array<int, array<string, mixed>>  $edits
     * @return array<int, array{label: string, note: string|null}>
     */
    private function editLog(array $edits): array
    {
        $lines = [];

        foreach ($edits as $e) {
            $label = self::EDIT_LABELS[$e['type']].($e['type'] === 'skill_removed' ? ': '.$e['name'] : '');
            $key = $label.'|'.($e['note'] ?? '');
            $lines[$key]['label'] = $label;
            $lines[$key]['note'] = $e['note'] ?? null;
            $lines[$key]['count'] = ($lines[$key]['count'] ?? 0) + 1;
        }

        return array_values(array_map(fn (array $l) => ['label' => $l['count'] > 1 ? "{$l['label']} ({$l['count']})" : $l['label'], 'note' => $l['note']], $lines));
    }

    private function pages(ResumeVariant $variant): ?int
    {
        if (isset($variant->edit_log['layout']['pages'])) {
            return (int) $variant->edit_log['layout']['pages'];
        }

        $attempt = $variant->generationAttempts()->where('generation_type', GenerationType::ResumePdf)->latest('id')->first();

        return $attempt?->pdf_page_count;
    }

    /**
     * @return array<int, string>
     */
    private function factKeys(ResumeVariant $variant): array
    {
        $keys = [];

        foreach ((array) ($variant->review_report['items'] ?? []) as $item) {
            array_push($keys, ...array_column($item['evidence'], 'fact_key'));
        }

        foreach ((array) ($variant->selection_input_snapshot['tailoring']['composition'] ?? []) as $finding) {
            array_push($keys, ...$finding['selected'], ...$finding['available']);
        }

        return array_values(array_unique($keys));
    }
}
