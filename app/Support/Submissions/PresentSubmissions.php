<?php

namespace App\Support\Submissions;

use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Support\ResumeRevision\JobResumes;

/**
 * How submissions are shown: the Opportunity workspace's full records, and the compact indicator on a list row. The state
 * shown is always the derived one (SummarizeSubmission); nothing here reads the inspection Application, which is a
 * different thing and never counts as an application having been made.
 */
final class PresentSubmissions
{
    public function __construct(
        private readonly SummarizeSubmission $summaries,
        private readonly JobResumes $resumes,
        private readonly SubmittedResumeSnapshot $snapshots,
    ) {}

    /**
     * The workspace's section: this job's submissions (newest first), and what the recording drawer needs.
     *
     * @return array<string, mixed>
     */
    public function forWorkspace(JobPosting $job): array
    {
        $history = $this->resumes->history($job);
        $labels = collect($history)->pluck('label', 'id');
        $submissions = $job->submissions()->with('events')->get()->sortByDesc(fn (Submission $s) => [$s->submitted_at->getTimestamp(), $s->id])->values();

        return [
            'records' => $submissions->map(fn (Submission $s) => $this->record($s, $labels->get($s->resume_variant_id)))->all(),
            'form' => [
                'application_url' => $job->source_url,
                'resumes' => $this->resumeOptions($job, $history),
            ],
        ];
    }

    /**
     * One compact indicator per job, for the Opportunities list: the submission that matters most (an active one, else
     * the latest that is not a mistake), or null when none was recorded.
     *
     * @param  iterable<int, int>  $jobIds
     * @return array<int, array<string, mixed>>
     */
    public function indicatorsByJob(iterable $jobIds): array
    {
        $out = [];
        $byJob = Submission::query()->whereIn('job_posting_id', collect($jobIds)->all())->with('events')->get()->groupBy('job_posting_id');

        foreach ($byJob as $jobId => $submissions) {
            $rows = $submissions->map(fn (Submission $s) => [$s, $this->summaries->fromEvents($s->events)])
                ->reject(fn (array $pair) => $pair[1]->recordedInError)
                ->sortByDesc(fn (array $pair) => [$pair[1]->active, $pair[0]->submitted_at->getTimestamp(), $pair[0]->id]);

            if ($rows->isEmpty()) {
                continue;
            }

            [$submission, $summary] = $rows->first();
            $out[(int) $jobId] = [
                'id' => $submission->id,
                'phase' => $summary->phase->value,
                'label' => $summary->phase->label(),
                'active' => $summary->active,
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'count' => $rows->count(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(Submission $submission, ?string $resumeLabel): array
    {
        $summary = $this->summaries->fromEvents($submission->events);

        return [
            'id' => $submission->id,
            'submitted_at' => $submission->submitted_at->toIso8601String(),
            'recorded_at' => $submission->created_at?->toIso8601String(),
            'method' => $submission->method->value,
            'phase' => $summary->phase->value,
            'phase_label' => $summary->phase->label(),
            'active' => $summary->active,
            'confirmed' => $summary->confirmed,
            'application_url' => $submission->application_url,
            'notes' => $submission->notes,
            'resume' => $submission->resume_variant_id === null ? null : ['id' => $submission->resume_variant_id, 'label' => $resumeLabel],
            // A copy of the exact PDF is kept only when there was real file to keep.
            'pdf_kept' => $submission->submitted_pdf_sha256 !== null,
            'inspection_linked' => $submission->application_id !== null,
        ];
    }

    /**
     * The job's own resumes a person may say they used, the final one flagged but never chosen for them.
     *
     * @param  array<int, array<string, mixed>>  $history  from JobResumes::history()
     * @return array<int, array<string, mixed>>
     */
    private function resumeOptions(JobPosting $job, array $history): array
    {
        $variants = $this->resumes->variants($job)->keyBy('id');
        $options = [];

        foreach ($history as $entry) {
            $variant = $variants->get($entry['id']);

            $options[] = [
                'id' => $entry['id'],
                'label' => $entry['label'],
                'created_at' => $entry['created_at'],
                'is_final' => (bool) $entry['preferred'],
                'is_current' => (bool) $entry['current'],
                // Whether Well Applied still holds this resume's PDF, so a copy could be kept. Checked, never rendered.
                'pdf_cached' => $variant instanceof ResumeVariant && $this->snapshots->isServedPdfCached($variant),
            ];
        }

        return $options;
    }
}
