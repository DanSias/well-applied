<?php

namespace App\Support\ProfileSynthesis;

use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeVariant;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use App\Support\ResumeVariant\GenerateResumeVariant;
use Illuminate\Validation\ValidationException;

/**
 * Composes the reference resume from an APPROVED, current profile and renders its PDF. The model
 * call is the existing Wording stage only; selection is the user's approved profile. What it
 * cost and what was selected is recorded on the profile version, so the first reference resume
 * doubles as the quality benchmark for this approach.
 */
final class GenerateReferenceResume
{
    public function __construct(
        private readonly GenerateResumeVariant $variants,
        private readonly GenerateResumePdf $renderer,
        private readonly GenerateResumeDocument $documents,
        private readonly ResumePdfStore $store,
        private readonly ResumePdfValidator $pdfValidator,
    ) {}

    public function generate(ResumeProfileSynthesis $synthesis): ResumeVariant
    {
        if ($synthesis->status !== 'approved') {
            throw ValidationException::withMessages(['profile' => 'Approve the profile before generating a reference resume.']);
        }

        if ($synthesis->isStale()) {
            throw ValidationException::withMessages(['profile' => 'Your approved evidence changed after this profile was approved. Build and approve a new version first.']);
        }

        $metrics = app(GenerationMetrics::class);
        $metrics->reset();
        $started = now();
        $this->record($synthesis, ['state' => 'running', 'started_at' => $started->toIso8601String(), 'phase' => 'selection']);

        try {
            $variant = $this->variants->generateReference($synthesis, fn (string $phase) => $this->record($synthesis, ['phase' => $phase]));
            $this->record($synthesis, ['phase' => 'pdf']);
            $composed = $this->renderer->compose($variant);
            $this->pdfValidator->validate($composed->pdf);
            $this->store->put($this->documents->generate($variant), $composed->pdf);
        } catch (\Throwable $e) {
            $this->record($synthesis, ['state' => 'failed', 'finished_at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 600), 'events' => $metrics->events()]);

            throw $e;
        }

        $this->record($synthesis, [
            'state' => 'ready', 'variant_id' => $variant->id, 'finished_at' => now()->toIso8601String(), 'phase' => null, 'error' => null,
            'duration_ms' => (int) $started->diffInMilliseconds(now()), 'selection_ms' => $metrics->stageMs('selection'), 'wording_ms' => $metrics->stageMs('wording'), 'render_ms' => $composed->renderMs,
            'pages' => $composed->pageCount, 'layout_mode' => $composed->mode, 'model_calls' => $metrics->stageCalls('wording'), 'tokens' => $metrics->tokenColumns(), 'events' => $metrics->events(),
        ]);

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function record(ResumeProfileSynthesis $synthesis, array $state): void
    {
        $synthesis->refresh();
        $usage = $synthesis->usage ?? [];
        $usage['reference'] = [...($usage['reference'] ?? []), ...$state];
        $synthesis->update(['usage' => $usage]);
    }
}
