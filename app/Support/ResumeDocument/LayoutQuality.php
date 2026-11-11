<?php

namespace App\Support\ResumeDocument;

/**
 * Judges a measured resume layout, from numbers alone, as a reader would: is the last page mostly empty, and if so, is
 * that a presentation defect to fix, harmless whitespace, or simply all the relevant evidence there is?
 *
 * It never renders, selects, rewrites or pads anything. It reads what a render already measured (pages, how full the
 * last page is, where each employer's bullets landed) and, when it is known, which relevant approved facts are not on
 * the resume, and returns a status, a severity and diagnostics a person can act on. `review` means a person should
 * look; nothing here blocks an export or triggers a retry.
 *
 * Statuses:
 *   balanced     two pages, the second at least `light_below` full
 *   light        the second page is between `underfilled_below` and `light_below` full: noted, not a defect
 *   underfilled  the second page is under `underfilled_below` full: a presentation defect, with its likely cause
 *   one_page     a single page: a legitimate length, never a defect
 *   unmeasured   the fill could not be measured: nothing is claimed
 *
 * Causes of an underfilled last page, in the diagnostics: relevant approved evidence is available to balance it
 * (`evidence_available`), some is (`evidence_partial`), none is (`evidence_exhausted`, the honest length), or it was
 * not looked up (`evidence_unknown`). `one_page_within_reach` is added when only a few bullets separate it from a
 * one-page resume.
 */
final class LayoutQuality
{
    /**
     * @param  array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null  $placement  where each role's bullets fell, from BulletPlacement
     * @param  array<int, array{key: string, role_id: int|null, statement: string, project: string|null}>|null  $evidence  relevant, shareable approved facts not on the resume; null when not looked up
     * @return array{status: string, severity: string|null, pages: int|null, fill: float|null, summary: string, findings: array<int, array{code: string, severity: string, message: string}>, room: array<string, mixed>|null}
     */
    public function assess(?int $pages, ?float $lastPageFill, ?array $placement = null, ?array $evidence = null, ?float $parentFill = null): array
    {
        $cfg = (array) config('resume.layout_quality');
        $findings = [];
        $room = null;
        $add = function (string $code, string $severity, string $message) use (&$findings): void {
            $findings[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
        };

        if ($pages === null || $lastPageFill === null) {
            return $this->result('unmeasured', null, $pages, $lastPageFill, 'The layout could not be measured, so no page-balance judgment is made.', $findings, null);
        }

        $percent = (int) round($lastPageFill * 100);

        if ($pages <= 1) {
            return $this->result('one_page', null, $pages, $lastPageFill, 'A one-page resume: a legitimate length.', $findings, null);
        }

        $status = match (true) {
            $lastPageFill < $cfg['underfilled_below'] => 'underfilled',
            $lastPageFill < $cfg['light_below'] => 'light',
            default => 'balanced',
        };

        if ($status === 'underfilled') {
            $room = $this->room($lastPageFill, $cfg, $evidence);
            $add('underfilled_last_page', 'review', "Page {$pages} is only {$percent}% full.");
            $this->explainCause($add, $room, $percent);

            $over = $this->bulletsOverOnePage($lastPageFill, $cfg);

            if ($over <= $cfg['one_page_within_bullets']) {
                $add('one_page_within_reach', 'review', "Removing about {$over} of the weakest bullets would make this a one-page resume, which would read better than a page that is {$percent}% full.");
            }
        } elseif ($status === 'light') {
            $add('light_last_page', 'informational', "Page {$pages} is {$percent}% full: shorter than ideal, not a defect.");
        }

        if ($parentFill !== null && $parentFill - $lastPageFill >= $cfg['revision_drop'] && $lastPageFill < $cfg['light_below']) {
            $before = (int) round($parentFill * 100);
            $add('fill_dropped_by_revision', 'review', "This revision took the last page from {$before}% to {$percent}% full; the bullets it removed are what left the page short.");
        }

        foreach ($this->splits($placement, $status !== 'balanced') as $finding) {
            $add($finding['code'], $finding['severity'], $finding['message']);
        }

        $severity = collect($findings)->contains('severity', 'review') ? 'review' : ($findings === [] ? null : 'informational');
        $summary = match ($status) {
            'underfilled' => "Page {$pages} is only {$percent}% full.",
            'light' => "Page {$pages} is {$percent}% full.",
            default => 'The pages are reasonably balanced.',
        };

        return $this->result($status, $severity, $pages, $lastPageFill, $summary, $findings, $room);
    }

    /**
     * What the gap is worth: roughly how many bullets would bring the last page up to "light", and which relevant
     * approved facts could be those bullets.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<int, array<string, mixed>>|null  $evidence
     * @return array{bullets_to_balance: int, projected_fill: float|null, available: int|null, candidates: array<int, array<string, mixed>>}
     */
    private function room(float $fill, array $cfg, ?array $evidence): array
    {
        $needed = max(1, (int) ceil(($cfg['light_below'] - $fill) * $cfg['later_page_points'] / $cfg['bullet_points']));
        $available = $evidence === null ? null : count($evidence);
        $projected = $available === null ? null : round(min(1.0, $fill + min($available, $needed) * $cfg['bullet_points'] / $cfg['later_page_points']), 2);

        return ['bullets_to_balance' => $needed, 'projected_fill' => $projected, 'available' => $available, 'candidates' => array_slice($evidence ?? [], 0, 5)];
    }

    /**
     * @param  array{bullets_to_balance: int, projected_fill: float|null, available: int|null, candidates: array<int, array<string, mixed>>}  $room
     */
    private function explainCause(callable $add, array $room, int $percent): void
    {
        $needed = $room['bullets_to_balance'];
        $available = $room['available'];

        if ($available === null) {
            $add('evidence_unknown', 'review', "About {$needed} more bullets would balance it, but the approved evidence was not checked for what else is relevant.");

            return;
        }

        if ($available === 0) {
            $add('evidence_exhausted', 'informational', 'No relevant approved evidence is left off this resume, so this is the honest length for this job. Do not pad it; consider a one-page version if it is close, or add evidence to the career profile.');

            return;
        }

        $keys = implode(', ', array_column(array_slice($room['candidates'], 0, 3), 'key'));

        if ($available >= $needed) {
            $add('evidence_available', 'review', "{$available} relevant approved facts are not on the resume, enough to balance it (about {$needed} bullets): {$keys}. Add the strongest instead of leaving the page short.");

            return;
        }

        $projected = (int) round(($room['projected_fill'] ?? 0) * 100);
        $add('evidence_partial', 'review', "Only {$available} relevant approved facts are not on the resume ({$keys}); adding them would bring page 2 to about {$projected}% (balancing needs about {$needed}). Beyond that there is no more relevant evidence.");
    }

    /**
     * About how many bullets the last page holds above what a one-page resume could absorb.
     *
     * @param  array<string, mixed>  $cfg
     */
    private function bulletsOverOnePage(float $fill, array $cfg): int
    {
        return (int) ceil($fill * $cfg['later_page_points'] / $cfg['bullet_points']);
    }

    /**
     * @param  array<int, array{employer: string, title: string, bullets: int, by_page: array<int, int>}>|null  $placement
     * @return array<int, array{code: string, severity: string, message: string}>
     */
    private function splits(?array $placement, bool $pageIsShort): array
    {
        $found = [];

        foreach ($placement ?? [] as $role) {
            $pages = array_keys(array_filter($role['by_page']));

            if (count($pages) < 2) {
                continue;
            }

            $counts = implode(' and ', array_map(fn (int $p) => "{$role['by_page'][$p]} on page {$p}", $pages));
            $small = min([PHP_INT_MAX, ...array_values($role['by_page'])]) === 1 && $role['bullets'] >= 3;
            $found[] = [
                'code' => $small ? 'role_split_awkward' : 'role_split',
                'severity' => 'informational',
                'message' => "{$role['title']} at {$role['employer']} continues across pages ({$counts})".($small ? ': a single bullet is left on its own side of the break.'.($pageIsShort ? ' Moving it or one bullet from the other side would end the split cleanly.' : '') : '.'),
            ];
        }

        return $found;
    }

    /**
     * @param  array<int, array{code: string, severity: string, message: string}>  $findings
     * @param  array<string, mixed>|null  $room
     * @return array{status: string, severity: string|null, pages: int|null, fill: float|null, summary: string, findings: array<int, array{code: string, severity: string, message: string}>, room: array<string, mixed>|null}
     */
    private function result(string $status, ?string $severity, ?int $pages, ?float $fill, string $summary, array $findings, ?array $room): array
    {
        return ['status' => $status, 'severity' => $severity, 'pages' => $pages, 'fill' => $fill, 'summary' => $summary, 'findings' => $findings, 'room' => $room];
    }
}
