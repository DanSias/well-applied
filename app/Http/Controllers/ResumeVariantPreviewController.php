<?php

namespace App\Http\Controllers;

use App\Models\ResumeVariant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app preview surface for one ResumeVariant's candidate-facing
 * document — an Inertia page (Career Toolkit's normal chrome/nav stays
 * visible) embedding the actual generated PDF via an `<iframe>`, rather
 * than an independently-paginated HTML rendering.
 *
 * This is deliberate, not a downgrade: ordinary screen HTML has no way
 * to expose Chromium's real paged-media fragmentation (the same engine
 * PDF export uses), so any CSS-only "simulated pages" preview could
 * visually disagree with the actual downloaded PDF — silently
 * misleading. Embedding the real PDF bytes (via
 * App\Http\Controllers\ResumeVariantPdfController's own `?inline=1`
 * toggle on the exact same route/generator "Download PDF" uses)
 * guarantees the preview's page boundaries are always byte-identical to
 * what gets downloaded, using the browser's own native PDF viewer —
 * no PDF.js, no second pagination engine, no duplicated generation
 * logic. See docs/resume-variant-generation.md "PDF export".
 *
 * Deliberately separate from ResumeVariantController, which stays
 * exactly what it already is: an Inertia/React audit and review
 * surface (citations, posture badges, visibility) — not a resume-
 * shaped document. This route shows the resume itself.
 */
class ResumeVariantPreviewController extends Controller
{
    public function show(ResumeVariant $resumeVariant): Response
    {
        return Inertia::render('resume-variants/preview', [
            'resumeVariant' => ['id' => $resumeVariant->id],
            'context' => $this->context($resumeVariant),
            'downloadUrl' => route('resume-variants.pdf', $resumeVariant),
            'pdfUrl' => route('resume-variants.pdf', ['resumeVariant' => $resumeVariant, 'inline' => 1]),
        ]);
    }

    /**
     * Where this resume lives, so the standalone preview can lead back to its workspace and job.
     *
     * @return array{title: string, workspace_url: string|null, job_url: string|null, back_label: string, back_url: string|null}
     */
    private function context(ResumeVariant $variant): array
    {
        $analysis = $variant->jobAnalysis ?? $variant->jobMatch?->jobAnalysis;
        $job = $analysis?->jobPosting;

        if ($job === null) {
            $profile = $variant->synthesis?->resumeProfile;

            return ['title' => 'Reference resume', 'workspace_url' => null, 'job_url' => null, 'back_label' => 'Back to resume profile', 'back_url' => $profile === null ? null : route('resume-profiles.show', $profile)];
        }

        $workspace = $variant->resume_profile_synthesis_id !== null
            ? route('jobs.profile-resumes.show', [$job, $variant])
            : route('jobs.analyses.matches.resume.show', [$job, $analysis, $variant->job_match_id, $variant]);

        return ['title' => "{$job->title} at {$job->company}", 'workspace_url' => $workspace, 'job_url' => route('jobs.show', $job), 'back_label' => 'Back to resume', 'back_url' => $workspace];
    }
}
