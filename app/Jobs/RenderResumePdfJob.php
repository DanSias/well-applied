<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Jobs\Concerns\RecordsAttemptMetrics;
use App\Models\GenerationAttempt;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\AssessResumeLayout;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use App\Support\ResumeRevision\FinishResumeLayout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The "Rendering and validating PDF" stage of the one-click pipeline:
 * runs the measured composition (GenerateResumePdf::compose), validates
 * the result (ResumePdfValidator), and caches the file so preview and
 * download reuse it. Makes no provider call. Recorded as its own
 * GenerationAttempt so the stage has the same durable status, timing,
 * claiming and retry behaviour as the others; $tries = 1 for the same
 * reason as the generation jobs. The terminal stage: nothing follows it.
 */
final class RenderResumePdfJob implements ShouldQueue
{
    use Queueable;
    use RecordsAttemptMetrics;

    public int $tries = 1;

    public function __construct(
        public readonly GenerationAttempt $attempt,
    ) {}

    public function handle(GenerateResumePdf $renderer, GenerateResumeDocument $documents, ResumePdfStore $store, ResumePdfValidator $validator, ?AssessResumeLayout $layout = null, ?FinishResumeLayout $finish = null): void
    {
        $layout ??= app(AssessResumeLayout::class);
        $finish ??= app(FinishResumeLayout::class);
        $this->attempt->update(['status' => GenerationStatus::Running, 'started_at' => now(), 'phase' => 'rendering']);
        $this->beginMetrics();

        $variant = $this->attempt->subject;

        if (! $variant instanceof ResumeVariant) {
            $this->markFailed('unexpected_error', 'GenerationAttempt subject was not a ResumeVariant.');

            return;
        }

        try {
            $composed = $renderer->compose($variant);
            $validator->validate($composed->pdf);
            $store->put($documents->generate($variant), $composed->pdf);
        } catch (ResumePdfPageBudgetExceededException $e) {
            $this->markFailed('pdf_page_budget', "The resume rendered to {$e->pageCount} pages, over the {$e->maxPages}-page limit, even after dropping every optional block.");

            return;
        } catch (InvalidResumePdfException $e) {
            $this->markFailed('pdf_invalid', 'The generated PDF could not be verified: '.$e->getMessage());

            return;
        }

        // The first render and its judgment are final for this variant. One bounded correction may follow for an underfilled
        // last page; it saves a new derived version or nothing, never changes this one, and its outcome is recorded beside the render.
        $diagnostics = $finish->finish($variant, $composed, [...$composed->diagnostics(), 'quality' => $layout->forVariant($variant, $composed)]);

        $this->attempt->update([
            'status' => GenerationStatus::Succeeded,
            'finished_at' => now(),
            'result_id' => $variant->id,
            'render_ms' => $composed->renderMs,
            'pdf_page_count' => $composed->pageCount,
            'layout_mode' => $composed->mode,
            // The page-balance judgment travels with the measurements: a short last page is flagged, never silently accepted.
            'layout_diagnostics' => $diagnostics,
            ...$this->successMetrics(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Resume PDF render failed unexpectedly.', [
            'generation_attempt_id' => $this->attempt->id,
            'exception' => $exception::class,
        ]);

        $this->markFailed('unexpected_error', 'Rendering the PDF failed unexpectedly.');
    }

    private function markFailed(string $category, string $message): void
    {
        $this->attempt->update([
            'status' => GenerationStatus::Failed,
            'finished_at' => now(),
            'failure_category' => $category,
            'failure_message' => $message,
        ]);
    }
}
