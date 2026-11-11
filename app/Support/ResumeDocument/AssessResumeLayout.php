<?php

namespace App\Support\ResumeDocument;

use App\Enums\GenerationType;
use App\Models\ResumeVariant;
use App\Support\ResumeRevision\ReplacementFacts;
use Illuminate\Support\Facades\Cache;

/**
 * Judges one resume variant's measured layout (LayoutQuality) with what only the variant's own records can add: how full
 * its parent was (so a revision that left a page short is named as the cause), and which relevant approved facts are not
 * on it (so a short page is either "add these" or "there is nothing more relevant to say").
 *
 * The evidence is looked up only when the last page is underfilled and the variant is a resume written for a job from an
 * approved profile, since only then is there a job to be relevant to; a reference resume, or one with no job, reports
 * "not checked" rather than guessing. Only Public facts are offered, and only the ones the revision tool already ranks
 * as relevant. Nothing is selected, added or re-rendered here, and no model is called.
 */
final class AssessResumeLayout
{
    public function __construct(private readonly LayoutQuality $quality, private readonly ReplacementFacts $replacements) {}

    /**
     * At render time, from the composition just measured.
     *
     * @return array<string, mixed>
     */
    public function forVariant(ResumeVariant $variant, ComposedResumePdf $composed): array
    {
        return $this->assess($variant, $composed->pageCount, $composed->lastPageFill, $composed->placement, $this->parentFill($variant));
    }

    /**
     * For a variant rendered before this judgment existed, or any stored layout: the judgment is the stored one when
     * there is one, otherwise made now from the stored pages and fill (which are measurements, so no render happens).
     *
     * @param  array<string, mixed>|null  $layout  pages, mode, last_page_fill, and quality when stored
     * @return array<string, mixed>|null
     */
    public function forStored(ResumeVariant $variant, ?array $layout): ?array
    {
        if ($layout === null) {
            return null;
        }

        if (is_array($layout['quality'] ?? null)) {
            return $layout['quality'];
        }

        $pages = isset($layout['pages']) ? (int) $layout['pages'] : null;
        $fill = isset($layout['last_page_fill']) ? (float) $layout['last_page_fill'] : null;

        // The lookup walks the job's evidence, so a finished answer is kept until the measurement changes.
        return Cache::remember("layout-quality:{$variant->id}:{$pages}:{$fill}", 3600, fn () => $this->assess($variant, $pages, $fill, null, $this->parentFill($variant)));
    }

    /**
     * @param  array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null  $placement
     * @return array<string, mixed>
     */
    private function assess(ResumeVariant $variant, ?int $pages, ?float $fill, ?array $placement, ?float $parentFill): array
    {
        $cfg = (array) config('resume.layout_quality');
        $underfilled = $pages !== null && $pages > 1 && $fill !== null && $fill < $cfg['underfilled_below'];

        return $this->quality->assess($pages, $fill, $placement, $underfilled ? $this->unusedRelevantEvidence($variant) : null, $parentFill);
    }

    /**
     * The last page's fill as stored for the variant this one was revised from: a revision records it in its edit log, a
     * generated resume in its PDF stage's diagnostics.
     */
    public function storedFill(ResumeVariant $variant): ?float
    {
        $fill = $variant->edit_log['layout']['last_page_fill'] ?? null;

        if ($fill !== null) {
            return (float) $fill;
        }

        $attempt = $variant->generationAttempts()->where('generation_type', GenerationType::ResumePdf)->latest('id')->first();
        $fill = $attempt?->layout_diagnostics['last_page_fill'] ?? null;

        return $fill === null ? null : (float) $fill;
    }

    private function parentFill(ResumeVariant $variant): ?float
    {
        $parent = $variant->parent_variant_id === null ? null : ResumeVariant::query()->find($variant->parent_variant_id);

        return $parent === null ? null : $this->storedFill($parent);
    }

    /**
     * Relevant, shareable approved facts that are not on the resume, best first: what the revision tool would suggest
     * adding under each role, restricted to Public facts. Null when there is no job to be relevant to.
     *
     * @return array<int, array{key: string, role_id: int, statement: string, project: string|null}>|null
     */
    private function unusedRelevantEvidence(ResumeVariant $variant): ?array
    {
        $variant->loadMissing(['jobAnalysis.jobPosting', 'synthesis', 'experienceRoles']);

        if ($variant->jobAnalysis?->jobPosting === null || $variant->synthesis === null) {
            return null;
        }

        $found = [];

        foreach ($variant->experienceRoles as $role) {
            foreach ($this->replacements->forRole($variant->jobAnalysis->jobPosting, $variant, $role->role_id) as $fact) {
                if ($fact['suggested'] && $fact['visibility'] === 'public' && ! isset($found[$fact['key']])) {
                    $found[$fact['key']] = ['key' => $fact['key'], 'role_id' => $role->role_id, 'statement' => mb_strimwidth((string) $fact['statement'], 0, 160, '...'), 'project' => $fact['project']];
                }
            }
        }

        return array_values($found);
    }
}
