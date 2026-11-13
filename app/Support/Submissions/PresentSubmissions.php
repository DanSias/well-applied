<?php

namespace App\Support\Submissions;

use App\Enums\JobDisposition;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\Submission;
use App\Support\Navigation\JobHome;
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
        $submissions = $job->submissions()->with('events')->get()->sortByDesc(fn (Submission $s) => [$s->displayDate(), $s->submitted_at->getTimestamp(), $s->id])->values();

        return [
            'records' => $submissions->map(fn (Submission $s) => $this->record($s, $labels->get($s->resume_variant_id)))->all(),
            'form' => [
                'application_url' => $job->source_url,
                // What an upload may really be, which can be less than the snapshot limit on a server set to accept less.
                'max_pdf_bytes' => SubmittedResumeSnapshot::maxUploadBytes(),
                'resumes' => $this->resumeOptions($job, $history),
            ],
        ];
    }

    /**
     * The Applications page: every submission of this profile's jobs, newest first, whether or not the job is still
     * pursued. A job may have several, each its own row (numbered when there is more than one). Records marked in error
     * are not mixed into the list; they come back separately. State is the derived one, never stored or guessed.
     *
     * @return array{applications: list<array<string, mixed>>, in_error: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function forIndex(CareerProfile $profile): array
    {
        $submissions = Submission::query()
            ->whereHas('jobPosting', fn ($q) => $q->where('career_profile_id', $profile->id))
            ->with(['jobPosting', 'events'])
            ->get();
        $labels = [];
        $rows = [];

        foreach ($submissions as $submission) {
            $job = $submission->jobPosting;
            $labels[$job->id] ??= collect($this->resumes->history($job))->pluck('label', 'id');
            $summary = $this->summaries->fromEvents($submission->events);

            $rows[] = [
                'id' => $submission->id,
                'job' => [
                    'id' => $job->id,
                    'title' => $job->title,
                    'company' => $job->company,
                    'pursued' => $job->disposition === JobDisposition::Pursued,
                    // A pursued job's workspace, any other job's own page: the same rule as every other link back to a job.
                    'url' => JobHome::url($job),
                ],
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'submitted_on' => $submission->displayDate(),
                'method' => $submission->method->value,
                'phase' => $summary->phase->value,
                'phase_label' => $summary->phase->label(),
                'active' => $summary->active,
                'in_error' => $summary->recordedInError,
                'resume' => $submission->resume_variant_id !== null
                    ? ($labels[$job->id]->get($submission->resume_variant_id) ?? 'A Well Applied resume')
                    : ($submission->submitted_pdf_sha256 !== null ? 'A PDF you uploaded' : null),
                'pdf_kept' => $submission->submitted_pdf_sha256 !== null,
                'application_url' => $submission->application_url,
                '_order' => [$submission->displayDate(), $submission->submitted_at->getTimestamp(), $submission->id],
            ];
        }

        // Newest first, then number each job's own applications oldest to newest ("2 of 3"), mistakes excluded.
        usort($rows, fn (array $a, array $b) => $b['_order'] <=> $a['_order']);
        $perJob = collect($rows)->reject(fn (array $r) => $r['in_error'])->groupBy(fn (array $r) => $r['job']['id'])->map(fn ($g) => $g->count());
        $seen = [];

        foreach (array_reverse(array_keys($rows)) as $i) {
            $jobId = $rows[$i]['job']['id'];

            if ($rows[$i]['in_error']) {
                $rows[$i]['attempt'] = null;
                $rows[$i]['attempts'] = 1;

                continue;
            }

            $seen[$jobId] = ($seen[$jobId] ?? 0) + 1;
            $rows[$i]['attempt'] = $seen[$jobId];
            $rows[$i]['attempts'] = (int) $perJob->get($jobId, 1);
        }

        $rows = array_map(function (array $r) {
            unset($r['_order']);

            return $r;
        }, $rows);
        $normal = array_values(array_filter($rows, fn (array $r) => ! $r['in_error']));

        return [
            'applications' => $normal,
            'in_error' => array_values(array_filter($rows, fn (array $r) => $r['in_error'])),
            'counts' => [
                'recorded' => count($normal),
                'in_progress' => count(array_filter($normal, fn (array $r) => $r['active'])),
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
                ->sortByDesc(fn (array $pair) => [$pair[1]->active, $pair[0]->displayDate(), $pair[0]->submitted_at->getTimestamp(), $pair[0]->id]);

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
                'submitted_on' => $submission->displayDate(),
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
            'submitted_on' => $submission->displayDate(),
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
