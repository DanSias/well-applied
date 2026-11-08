<?php

namespace App\Support\ReadinessReview;

use App\Models\ResumeReadinessReview;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;

/**
 * The readiness state of one variant for the workspace. A finished review is shown as current only while everything it
 * was made from still matches: the job description, the analysis, the rendered document and the review prompt. Otherwise
 * the workspace is told the review is out of date and why, and its findings are not shown as if they applied.
 */
final class PresentReadiness
{
    public function __construct(
        private readonly ReadinessFingerprints $fingerprints,
        private readonly GenerateResumeDocument $documents,
        private readonly ResumePdfStore $store,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ResumeVariant $variant): array
    {
        $review = ResumeReadinessReview::query()->where('resume_variant_id', $variant->id)->latest('id')->first();

        if ($review === null) {
            return ['state' => 'none', 'prompt_version' => ReadinessPrompt::VERSION];
        }

        $base = ['review_id' => $review->id, 'requested_at' => $review->requested_at->toIso8601String(), 'started_at' => $review->started_at?->toIso8601String(), 'phase' => $review->phase];

        if ($review->isActive()) {
            return [...$base, 'state' => $review->status];
        }

        if ($review->status === 'failed') {
            return [...$base, 'state' => 'failed', 'message' => $review->failure_message];
        }

        $stale = $this->staleReasons($review, $variant);

        if ($stale !== []) {
            return [...$base, 'state' => 'stale', 'stale_reasons' => $stale, 'reviewed_at' => $review->finished_at?->toIso8601String()];
        }

        return [
            ...$base, 'state' => 'ready', 'reviewed_at' => $review->finished_at?->toIso8601String(), 'model' => $review->model, 'metrics' => $review->metrics,
            'fingerprints' => ['description' => $review->description_fingerprint, 'analysis' => $review->analysis_fingerprint, 'artifact' => $review->artifact_fingerprint, 'prompt' => $review->prompt_version],
            'result' => $review->result,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function staleReasons(ResumeReadinessReview $review, ResumeVariant $variant): array
    {
        $variant->loadMissing('jobAnalysis.jobPosting');
        $reasons = [];

        if ($review->description_fingerprint !== $this->fingerprints->description($variant->jobAnalysis->jobPosting)) {
            $reasons[] = 'The job description has changed since this review.';
        }

        if ($review->analysis_fingerprint !== $this->fingerprints->analysis($variant->jobAnalysis)) {
            $reasons[] = 'The job analysis has changed since this review.';
        }

        if ($review->artifact_fingerprint !== $this->store->key($this->documents->generate($variant))) {
            $reasons[] = 'The resume document has changed since this review.';
        }

        if ($review->prompt_version !== ReadinessPrompt::VERSION) {
            $reasons[] = 'The review method has been updated since this review.';
        }

        return $reasons;
    }
}
