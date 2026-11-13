<?php

namespace App\Support\ResumeRevision;

use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Models\JobResumeSelection;
use App\Models\ResumeVariant;
use Illuminate\Support\Collection;

/**
 * Which tailored resumes a job has, which one is being worked on, and which (if any) the user marked final.
 *
 * A variant is immutable, so the working copy and the final designation live on the job. The working copy is whatever
 * was last generated or revised; before any of that was recorded it is the newest generated variant. The final
 * designation changes only when the user changes it: generating or revising never moves it.
 */
final class JobResumes
{
    /**
     * Every profile-assisted resume made for this job, oldest first.
     *
     * @return Collection<int, ResumeVariant>
     */
    public function variants(JobPosting $posting): Collection
    {
        return ResumeVariant::query()
            ->whereIn('job_analysis_id', $posting->jobAnalyses()->select('id'))
            ->whereNotNull('resume_profile_synthesis_id')
            ->orderBy('id')
            ->get();
    }

    private function selection(JobPosting $posting): ?JobResumeSelection
    {
        return JobResumeSelection::query()->where('job_posting_id', $posting->id)->first();
    }

    public function current(JobPosting $posting): ?ResumeVariant
    {
        $variants = $this->variants($posting);
        $marked = $this->selection($posting)?->current_resume_variant_id;

        return $variants->firstWhere('id', $marked)
            ?? $variants->whereNull('parent_variant_id')->last()
            ?? $variants->last();
    }

    public function preferred(JobPosting $posting): ?ResumeVariant
    {
        $id = $this->selection($posting)?->preferred_resume_variant_id;

        return $id === null ? null : $this->variants($posting)->firstWhere('id', $id);
    }

    /**
     * Where each job's resume work stands: 'final' when the user marked one final, 'in-progress' when it has a
     * profile-assisted resume but none marked final. Jobs with no resume are absent. This is the definition the
     * Overview counts and the Opportunities "Resume" filter share.
     *
     * @param  iterable<int, int>  $jobIds
     * @return array<int, 'in-progress'|'final'>
     */
    public function stateByJob(iterable $jobIds): array
    {
        $jobIds = collect($jobIds)->all();
        $withResumes = ResumeVariant::query()
            ->whereNotNull('resume_profile_synthesis_id')
            ->whereIn('job_analysis_id', JobAnalysis::query()->whereIn('job_posting_id', $jobIds)->select('id'))
            ->with('jobAnalysis:id,job_posting_id')
            ->get(['id', 'job_analysis_id'])
            ->map(fn (ResumeVariant $v) => $v->jobAnalysis->job_posting_id)
            ->unique();
        $states = [];

        foreach (JobPosting::query()->whereIn('id', $withResumes)->get() as $posting) {
            if ($this->current($posting) !== null) {
                $states[$posting->id] = $this->preferred($posting) !== null ? 'final' : 'in-progress';
            }
        }

        return $states;
    }

    public function setCurrent(JobPosting $posting, ResumeVariant $variant): void
    {
        JobResumeSelection::query()->updateOrCreate(['job_posting_id' => $posting->id], ['current_resume_variant_id' => $variant->id]);
    }

    public function setPreferred(JobPosting $posting, ?ResumeVariant $variant): void
    {
        JobResumeSelection::query()->updateOrCreate(['job_posting_id' => $posting->id], ['preferred_resume_variant_id' => $variant?->id]);
    }

    /**
     * A compact, newest-first history for the workspace.
     *
     * @return array<int, array{id: int, kind: string, label: string, parent_id: int|null, created_at: string, summary: string, current: bool, preferred: bool}>
     */
    public function history(JobPosting $posting): array
    {
        $variants = $this->variants($posting);
        $current = $this->current($posting)?->id;
        $preferred = $this->preferred($posting)?->id;
        $generated = 0;
        $numbered = [];

        foreach ($variants as $variant) {
            if ($variant->parent_variant_id === null) {
                $generated++;
            }

            $numbered[$variant->id] = $generated;
        }

        return $variants->reverse()->values()->map(fn (ResumeVariant $v) => [
            'id' => $v->id,
            'kind' => $v->parent_variant_id === null ? 'generated' : 'revision',
            'label' => $v->parent_variant_id === null ? "Generated resume {$numbered[$v->id]}" : "Revision of resume {$numbered[$v->id]}",
            'parent_id' => $v->parent_variant_id,
            'created_at' => $v->generated_at->toIso8601String(),
            'summary' => $this->describe($v),
            'current' => $v->id === $current,
            'preferred' => $v->id === $preferred,
        ])->all();
    }

    private function describe(ResumeVariant $variant): string
    {
        $edits = (array) ($variant->edit_log['edits'] ?? []);

        if ($variant->parent_variant_id === null) {
            return 'Written from your approved profile';
        }

        $count = fn (string $type) => count(array_filter($edits, fn (array $e) => $e['type'] === $type));
        $parts = array_filter([
            $count('summary_edited') > 0 ? 'edited the summary' : null,
            ($n = $count('bullet_edited')) > 0 ? "edited {$n} ".($n === 1 ? 'bullet' : 'bullets') : null,
            ($n = $count('bullet_replaced')) > 0 ? "replaced {$n} ".($n === 1 ? 'bullet' : 'bullets') : null,
            ($n = $count('bullet_added')) > 0 ? "added {$n} ".($n === 1 ? 'bullet' : 'bullets') : null,
            ($n = $count('bullet_removed')) > 0 ? "removed {$n} ".($n === 1 ? 'bullet' : 'bullets') : null,
            $count('bullets_reordered') > 0 ? 'reordered bullets' : null,
            ($n = $count('skill_removed')) > 0 ? "removed {$n} ".($n === 1 ? 'skill' : 'skills') : null,
        ]);

        return $parts === [] ? 'Saved without changes' : ucfirst(implode(', ', $parts));
    }
}
