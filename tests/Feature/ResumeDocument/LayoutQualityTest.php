<?php

use App\Models\ResumeVariant;
use App\Support\ResumeDocument\AssessResumeLayout;
use App\Support\ResumeDocument\LayoutQuality;

/**
 * The page-balance judgment, from measurements alone (no render): when a last page that is mostly empty is a
 * presentation defect, when it is harmless, and when it just reflects how much relevant evidence there is.
 */
function judge(?int $pages, ?float $fill, ?array $placement = null, ?array $evidence = null, ?float $parentFill = null): array
{
    return app(LayoutQuality::class)->assess($pages, $fill, $placement, $evidence, $parentFill);
}

function evidenceFacts(int $count): array
{
    return array_map(fn (int $i) => ['key' => "fact-{$i}", 'role_id' => 1, 'statement' => "Built relevant system {$i}.", 'project' => "Project {$i}"], range(1, $count));
}

function codes(array $quality): array
{
    return array_column($quality['findings'], 'code');
}

it('calls a reasonably full second page balanced, with nothing for a person to do', function () {
    $quality = judge(2, 0.68);

    expect($quality['status'])->toBe('balanced')->and($quality['severity'])->toBeNull()->and($quality['findings'])->toBe([])->and($quality['room'])->toBeNull();
});

it('treats a second page that is a little short as light, noted but not a defect', function () {
    $quality = judge(2, 0.48);

    expect($quality['status'])->toBe('light')->and($quality['severity'])->toBe('informational')->and(codes($quality))->toBe(['light_last_page']);
});

it('calls a second page that is mostly empty a presentation defect and says it needs review', function () {
    $quality = judge(2, 0.36);

    expect($quality['status'])->toBe('underfilled')->and($quality['severity'])->toBe('review')->and(codes($quality))->toContain('underfilled_last_page')
        ->and($quality['summary'])->toBe('Page 2 is only 36% full.');
});

it('puts the line between "light" and "underfilled" at the configured thresholds', function () {
    expect(judge(2, 0.399)['status'])->toBe('underfilled')->and(judge(2, 0.40)['status'])->toBe('light')
        ->and(judge(2, 0.549)['status'])->toBe('light')->and(judge(2, 0.55)['status'])->toBe('balanced');

    config(['resume.layout_quality.underfilled_below' => 0.30]);

    expect(judge(2, 0.36)['status'])->toBe('light');
});

it('never calls a legitimate one-page resume a defect, however short', function () {
    foreach ([0.12, 0.5, 0.97] as $fill) {
        $quality = judge(1, $fill);

        expect($quality['status'])->toBe('one_page')->and($quality['severity'])->toBeNull()->and($quality['findings'])->toBe([]);
    }
});

it('claims nothing when the layout could not be measured', function () {
    expect(judge(2, null)['status'])->toBe('unmeasured')->and(judge(2, null)['severity'])->toBeNull()->and(judge(null, null)['findings'])->toBe([]);
});

it('says to add relevant approved evidence when enough of it is left off, naming it', function () {
    $quality = judge(2, 0.36, evidence: evidenceFacts(8));

    expect(codes($quality))->toContain('evidence_available')->not->toContain('evidence_exhausted', 'evidence_partial', 'evidence_unknown')
        ->and($quality['room']['bullets_to_balance'])->toBe(4)->and($quality['room']['available'])->toBe(8)
        // Adding the bullets it needs would bring the page up to "light".
        ->and($quality['room']['projected_fill'])->toBeGreaterThanOrEqual(0.55)
        ->and(array_column($quality['room']['candidates'], 'key'))->toBe(['fact-1', 'fact-2', 'fact-3', 'fact-4', 'fact-5'])
        ->and(collect($quality['findings'])->firstWhere('code', 'evidence_available')['message'])->toContain('fact-1, fact-2, fact-3');
});

it('reports how far a little relevant evidence goes, and that there is no more beyond it', function () {
    $quality = judge(2, 0.36, evidence: evidenceFacts(2));

    expect(codes($quality))->toContain('evidence_partial')->and($quality['room']['available'])->toBe(2)
        ->and($quality['room']['projected_fill'])->toBeLessThan(0.55)->and($quality['room']['projected_fill'])->toBeGreaterThan(0.36)
        ->and(collect($quality['findings'])->firstWhere('code', 'evidence_partial')['message'])->toContain('no more relevant evidence');
});

it('treats a job with no more relevant evidence as the honest length, not a defect to pad', function () {
    $quality = judge(2, 0.34, evidence: []);

    expect(codes($quality))->toContain('evidence_exhausted')->not->toContain('evidence_available', 'evidence_partial')
        ->and(collect($quality['findings'])->firstWhere('code', 'evidence_exhausted')['severity'])->toBe('informational')
        ->and(collect($quality['findings'])->firstWhere('code', 'evidence_exhausted')['message'])->toContain('Do not pad it')
        ->and($quality['room']['candidates'])->toBe([]);
});

it('does not guess what else is relevant when the evidence was not looked up', function () {
    $quality = judge(2, 0.36, evidence: null);

    expect(codes($quality))->toContain('evidence_unknown')->and($quality['room']['available'])->toBeNull()->and($quality['room']['projected_fill'])->toBeNull();
});

it('points out when only a few bullets separate a short last page from a one-page resume', function () {
    $near = judge(2, 0.05, evidence: []);
    $far = judge(2, 0.36, evidence: []);

    expect(codes($near))->toContain('one_page_within_reach')->and(codes($far))->not->toContain('one_page_within_reach')
        ->and(collect($near['findings'])->firstWhere('code', 'one_page_within_reach')['message'])->toContain('one-page');
});

it('names a revision that removed content as the reason the last page became short', function () {
    $quality = judge(2, 0.36, parentFill: 0.68);

    expect(codes($quality))->toContain('fill_dropped_by_revision')
        ->and(collect($quality['findings'])->firstWhere('code', 'fill_dropped_by_revision')['message'])->toContain('from 68% to 36%');

    // A small change, a page that is still full enough, or no earlier measurement is not blamed on the revision.
    expect(codes(judge(2, 0.60, parentFill: 0.68)))->not->toContain('fill_dropped_by_revision')
        ->and(codes(judge(2, 0.36, parentFill: 0.40)))->not->toContain('fill_dropped_by_revision')
        ->and(codes(judge(2, 0.36)))->not->toContain('fill_dropped_by_revision');
});

it('flags an employer that is split so one bullet is stranded on its own side, and only notes an even split', function () {
    $stranded = judge(2, 0.60, placement: [['employer' => 'RocketGate', 'title' => 'Developer', 'bullets' => 4, 'by_page' => [1 => 3, 2 => 1]]]);
    $even = judge(2, 0.60, placement: [['employer' => 'RocketGate', 'title' => 'Developer', 'bullets' => 4, 'by_page' => [1 => 2, 2 => 2]]]);
    $whole = judge(2, 0.60, placement: [['employer' => 'RocketGate', 'title' => 'Developer', 'bullets' => 4, 'by_page' => [1 => 4]], ['employer' => 'Pearson', 'title' => 'Lead', 'bullets' => 3, 'by_page' => [2 => 3]]]);

    expect(codes($stranded))->toBe(['role_split_awkward'])->and($stranded['findings'][0]['message'])->toContain('3 on page 1 and 1 on page 2')
        ->and(codes($even))->toBe(['role_split'])->and(codes($whole))->toBe([])
        // A page break between employers is not a split. A split alone is informational, never a review.
        ->and($stranded['severity'])->toBe('informational')->and($whole['severity'])->toBeNull();
});

it('suggests how to end a split cleanly only when the page is also short', function () {
    $short = judge(2, 0.45, placement: [['employer' => 'A', 'title' => 'T', 'bullets' => 4, 'by_page' => [1 => 1, 2 => 3]]]);
    $full = judge(2, 0.70, placement: [['employer' => 'A', 'title' => 'T', 'bullets' => 4, 'by_page' => [1 => 1, 2 => 3]]]);

    expect($short['findings'][1]['message'])->toContain('end the split cleanly')->and($full['findings'][0]['message'])->not->toContain('end the split cleanly');
});

it('does not penalize long, relevant accomplishments: a full page is balanced however few bullets fill it', function () {
    // Eight long bullets that fill the second page are a balanced resume; the judgment is the fill, not the bullet count.
    expect(judge(2, 0.72, placement: [['employer' => 'A', 'title' => 'T', 'bullets' => 8, 'by_page' => [1 => 8]]])['status'])->toBe('balanced');
});

it('re-judges a stored layout from its measurements and shows the stored judgment when there is one', function () {
    $variant = ResumeVariant::factory()->create();
    $assess = app(AssessResumeLayout::class);

    // A resume rendered before the judgment existed is judged now, so it is not shown as just "2 pages".
    $old = $assess->forStored($variant, ['pages' => 2, 'mode' => 'standard', 'last_page_fill' => 0.31]);

    expect($old['status'])->toBe('underfilled')->and($old['severity'])->toBe('review')
        // No job to be relevant to, so what else could be added is reported as not checked.
        ->and(codes($old))->toContain('evidence_unknown')
        ->and($assess->forStored($variant, ['pages' => 2, 'last_page_fill' => 0.31, 'quality' => ['status' => 'stored']]))->toBe(['status' => 'stored'])
        ->and($assess->forStored($variant, null))->toBeNull();
});

it('compares a revision with the variant it was revised from', function () {
    $parent = ResumeVariant::factory()->create(['edit_log' => ['edits' => [], 'layout' => ['pages' => 2, 'mode' => 'standard', 'last_page_fill' => 0.68]]]);
    $revision = ResumeVariant::factory()->create(['parent_variant_id' => $parent->id]);

    $quality = app(AssessResumeLayout::class)->forStored($revision, ['pages' => 2, 'mode' => 'standard', 'last_page_fill' => 0.36]);

    expect(codes($quality))->toContain('fill_dropped_by_revision')->and(app(AssessResumeLayout::class)->storedFill($parent))->toBe(0.68);
});
