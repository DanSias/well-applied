<?php

namespace App\Support\TailoredResume;

use App\Enums\ClarificationSessionStatus;
use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\ClarificationSession;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\EvidenceRevision;
use App\Support\CurrentCareerProfile;
use App\Support\GenerationAttempt\QueueGenerationAttempt;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV4;
use App\Support\JobMatch\Prompts\JobMatchPromptV3;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV6;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV3;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Generate Tailored Resume": one action that drives the existing
 * durable stages in dependency order, analysis -> match -> resume ->
 * PDF, reusing whatever is already valid and claiming only what is
 * missing. Application inspection is never part of it.
 *
 * Nothing here runs a stage itself and nothing is kept in memory: every
 * stage is a normal GenerationAttempt claimed through
 * QueueGenerationAttempt (atomic, duplicate-safe) and executed by the
 * existing job. A claimed attempt carries `chain = true`, so when its
 * job succeeds it calls advance() for the next missing stage; the
 * pipeline is therefore a pure function of persisted state, which is
 * what makes a retry resume from the failed stage (earlier stages are
 * valid and are simply reused) and a double click harmless (the claim
 * lock allows one winner).
 *
 * "Valid" means: the latest result for the stage was produced by the
 * current prompt versions and, for a match, for the current career
 * profile AND the current evidence revision. Approving new evidence
 * (App\Support\Clarification\EvidenceRevision) therefore makes an earlier
 * match stale, so it is re-run rather than reused with a corpus it never
 * saw. Other edits to canonical career data still do not invalidate a
 * match.
 *
 * Between matching and the resume stage the pipeline may pause for
 * optional clarification questions (App\Support\Clarification\ClarificationGate).
 * The pause is a persisted `pending` session, not an attempt: the user
 * answers, skips or chooses Generate Now, and continueAfterClarification()
 * resumes the same run.
 */
final class TailoredResumePipeline
{
    public function __construct(
        private readonly QueueGenerationAttempt $claims,
        private readonly JobAnalysisPromptV4 $analysisPrompt,
        private readonly JobMatchPromptV3 $matchPrompt,
        private readonly ResumeSelectionPromptV6 $selectionPrompt,
        private readonly ResumeWordingPromptV3 $wordingPrompt,
        private readonly GenerateResumeDocument $documents,
        private readonly ResumePdfStore $pdfStore,
        private readonly ClarificationGate $clarification,
    ) {}

    /**
     * The user action. Reuses an in-flight run when there is one,
     * otherwise opens a new run, then claims the next missing stage.
     * `$regenerate` forces a brand-new resume (and PDF) while still
     * reusing a valid analysis and match.
     *
     * @throws ValidationException when the description is not known to be complete and the user has not confirmed continuing.
     */
    public function start(JobPosting $posting, bool $allowIncompleteDescription = false, bool $regenerate = false): string
    {
        if ($posting->description_completeness !== DescriptionCompleteness::Complete && ! $allowIncompleteDescription) {
            throw ValidationException::withMessages([
                'allow_incomplete_description' => 'The description may be incomplete. Missing requirements or responsibilities can affect analysis. Confirm to generate anyway.',
            ]);
        }

        $active = $this->activeRun($posting);
        $runId = $active !== null
            ? (string) $active->pipeline_run_id
            : ($this->pendingRunId($posting) ?? (string) Str::uuid());

        $this->advance($posting, $runId, $regenerate);

        return $runId;
    }

    /**
     * Claims the first stage that is not yet satisfied, unless an
     * attempt is already in flight for the posting. Idempotent.
     */
    public function advance(JobPosting $posting, string $runId, bool $regenerate = false): void
    {
        $regenerate = $regenerate || $this->runIsRegenerating($posting, $runId);

        if ($this->activeAttempts($posting)->isNotEmpty()) {
            return;
        }

        $state = $this->inspect($posting, $regenerate ? $runId : null);

        // Optional clarification, only for a fresh resume and only once per
        // job analysis; a pending session holds the run here.
        if ($state['match'] !== null && $state['variant'] === null && ! $regenerate
            && $this->clarification->pauseIfNeeded($posting, $state['analysis'], $state['match'], $runId)) {
            return;
        }

        $next = match (true) {
            $state['analysis'] === null => [GenerationType::JobAnalysis, $posting],
            $state['match'] === null => [GenerationType::JobMatch, $state['analysis']],
            $state['variant'] === null => [GenerationType::ResumeVariant, $state['match']],
            ! $state['pdf_ready'] => [GenerationType::ResumePdf, $state['variant']],
            default => null,
        };

        if ($next === null) {
            return;
        }

        $this->claims->claim($next[0], $next[1], $runId, chain: true, regenerate: $regenerate);
    }

    /**
     * What is already valid, derived entirely from persisted state.
     * With `$regenerateRunId`, the resume stage is satisfied only by a
     * variant that attempt of this run produced.
     *
     * `evidence_stale` is true when a match exists for the current prompt
     * but was built before the latest approved evidence, so the match
     * stage will run again.
     *
     * @return array{analysis: JobAnalysis|null, match: JobMatch|null, variant: ResumeVariant|null, pdf_ready: bool, evidence_stale: bool}
     */
    public function inspect(JobPosting $posting, ?string $regenerateRunId = null): array
    {
        $analysis = $posting->jobAnalyses()
            ->where('prompt_version', $this->analysisPrompt->version())
            ->orderByDesc('generated_at')->orderByDesc('id')
            ->first();

        $profile = CurrentCareerProfile::resolve();

        $match = $analysis === null ? null : $analysis->jobMatches()
            ->where('career_profile_id', $profile->id)
            ->where('prompt_version', $this->matchPrompt->version())
            ->where('evidence_revision', EvidenceRevision::current($profile))
            ->orderByDesc('generated_at')->orderByDesc('id')
            ->first();

        $variant = null;

        $evidenceStale = $analysis !== null && $match === null && $analysis->jobMatches()
            ->where('career_profile_id', $profile->id)
            ->where('prompt_version', $this->matchPrompt->version())
            ->exists();

        if ($match !== null) {
            $variants = $match->resumeVariants()
                ->where('selection_prompt_version', $this->selectionPrompt->version())
                ->where('wording_prompt_version', $this->wordingPrompt->version())
                ->orderByDesc('generated_at')->orderByDesc('id');

            if ($regenerateRunId !== null) {
                $producedIds = GenerationAttempt::query()
                    ->where('pipeline_run_id', $regenerateRunId)
                    ->where('generation_type', GenerationType::ResumeVariant)
                    ->where('status', GenerationStatus::Succeeded)
                    ->pluck('result_id');
                $variants->whereIn('id', $producedIds);
            }

            $variant = $variants->first();
        }

        return [
            'analysis' => $analysis,
            'match' => $match,
            'variant' => $variant,
            'pdf_ready' => $variant !== null && $this->pdfStore->get($this->documents->generate($variant)) !== null,
            'evidence_stale' => $evidenceStale,
        ];
    }

    /**
     * Resumes a run that was held for clarification. `Skipped` is
     * Generate Now (no answers needed); `Completed` follows answers and
     * review. Either way the same run continues: if evidence was approved
     * meanwhile, the stale match is re-run and Selection then reads the
     * updated corpus.
     */
    public function continueAfterClarification(JobPosting $posting, ClarificationSession $session, ClarificationSessionStatus $as): string
    {
        $this->clarification->resolve($session, $as);

        $runId = $session->pipeline_run_id ?? (string) Str::uuid();
        $this->advance($posting, $runId);

        return $runId;
    }

    /**
     * Every attempt, of any stage, that belongs to this posting.
     *
     * @return Builder<GenerationAttempt>
     */
    public function attemptsFor(JobPosting $posting): Builder
    {
        $analysisIds = $posting->jobAnalyses()->pluck('id');
        $matchIds = JobMatch::query()->whereIn('job_analysis_id', $analysisIds)->pluck('id');
        $variantIds = ResumeVariant::query()->whereIn('job_match_id', $matchIds)->pluck('id');

        return GenerationAttempt::query()->where(function ($query) use ($posting, $analysisIds, $matchIds, $variantIds) {
            $query->where(fn ($q) => $q->where('subject_type', 'job_posting')->where('subject_id', $posting->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'job_analysis')->whereIn('subject_id', $analysisIds))
                ->orWhere(fn ($q) => $q->where('subject_type', 'job_match')->whereIn('subject_id', $matchIds))
                ->orWhere(fn ($q) => $q->where('subject_type', 'resume_variant')->whereIn('subject_id', $variantIds));
        });
    }

    /**
     * @return Collection<int, GenerationAttempt>
     */
    private function activeAttempts(JobPosting $posting): Collection
    {
        return $this->attemptsFor($posting)->active()->get();
    }

    private function activeRun(JobPosting $posting): ?GenerationAttempt
    {
        return $this->attemptsFor($posting)->active()->whereNotNull('pipeline_run_id')->latest('id')->first();
    }

    /**
     * A run held for clarification has no active attempt, so a second
     * click must still join it instead of opening a competing run.
     */
    private function pendingRunId(JobPosting $posting): ?string
    {
        return $this->clarification->pendingFor($posting)?->pipeline_run_id;
    }

    private function runIsRegenerating(JobPosting $posting, string $runId): bool
    {
        return $this->attemptsFor($posting)->where('pipeline_run_id', $runId)->where('regenerate', true)->exists();
    }
}
