<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Jobs\RenderResumePdfJob;
use App\Models\Education;
use App\Models\GenerationAttempt;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\ComposedResumePdf;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumeDocument;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use App\Support\ResumeDocument\ResumeSelectedProject;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\Process;
use Tests\Support\PearlyResumeVariantFixture;
use Tests\Support\ResumeVariantFixtures;

/**
 * Sprint 1 layout regressions, driven entirely by stored/factory
 * ResumeVariants (no provider call): punctuation and metadata, final
 * line wrapping, intentional one-page vs two-page composition, and
 * ATS-readable PDF text extraction. Uses real headless Chromium plus
 * `pdftotext` (poppler) for line-level extraction; tests that need it
 * skip when it is not installed.
 */
function layoutContext(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $candidate['profile']->update(['email' => 'daniel.sias@gmail.com']);
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    return [$candidate, $match];
}

function layoutVariant(array $bullets, string $summary = 'Full-stack software engineer building production systems.', array $roleOverrides = [], ?array $context = null): ResumeVariant
{
    [$candidate, $match] = $context ?? layoutContext();

    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $match->id,
        'summary' => $summary,
    ]);

    $role = $variant->experienceRoles()->create([
        'role_id' => $candidate['role']->id,
        'employer_name' => $roleOverrides['employer_name'] ?? $candidate['employer']->name,
        'display_title' => $roleOverrides['display_title'] ?? 'Senior Software Engineer',
        'start_year' => $candidate['role']->start_year,
        'start_month' => $candidate['role']->start_month,
        'end_year' => $candidate['role']->end_year,
        'end_month' => $candidate['role']->end_month,
        'display_order' => 1,
    ]);

    foreach (array_values($bullets) as $i => $text) {
        $role->bullets()->create(['resume_variant_id' => $variant->id, 'display_order' => $i + 1, 'text' => $text]);
    }

    return $variant->fresh();
}

function pdfPages(string $pdf): int
{
    return count((new PdfParser)->parseContent($pdf)->getPages());
}

/** @return array<int, string> Physical text lines of the PDF, whitespace-collapsed. */
function pdfLines(string $pdf): array
{
    $process = new Process(['pdftotext', '-layout', '-', '-']);
    $process->setInput($pdf)->mustRun();

    return array_values(array_filter(array_map(
        fn (string $line) => trim((string) preg_replace('/\s+/', ' ', $line)),
        explode("\n", $process->getOutput()),
    ), fn (string $line) => $line !== ''));
}

function pdftotextAvailable(): bool
{
    return (new Process(['pdftotext', '-v']))->run() === 0 || (new Process(['which', 'pdftotext']))->run() === 0;
}

function standardRender(ResumeVariant $variant): string
{
    $generator = app(GenerateResumePdf::class);
    $method = new ReflectionMethod($generator, 'render');
    $document = app(GenerateResumeDocument::class)->generate($variant);

    return $method->invoke($generator, $document, 'standard');
}

// --- Punctuation and metadata ---------------------------------------------

it('never emits an em dash in PDF text or metadata, even from stored text that contains one', function () {
    $variant = layoutVariant(
        ["Designed remediation tooling\u{2014}correcting records safely across two phases."],
        "Engineer who applies AI with strict boundaries\u{2014}never in the decision path.",
        ['employer_name' => "Acme \u{2014} Payments"],
    );

    $pdf = app(GenerateResumePdf::class)->generate($variant);
    $document = (new PdfParser)->parseContent($pdf);

    expect($document->getText())->not->toContain("\u{2014}")
        ->and(json_encode($document->getDetails()))->not->toContain('—')
        ->and($document->getDetails()['Title'] ?? '')->not->toContain("\u{2014}")
        ->and($document->getText())->toContain('boundaries, never in the decision path');
});

it('puts the approved contact email in the PDF text', function () {
    $pdf = app(GenerateResumePdf::class)->generate(layoutVariant(['Built a deployment pipeline on AWS.']));

    expect((new PdfParser)->parseContent($pdf)->getText())->toContain('daniel.sias@gmail.com')
        ->and((new PdfParser)->parseContent($pdf)->getText())->not->toContain('dan.sias@gmail.com');
});

// --- Final-line wrapping --------------------------------------------------

it('never leaves a single orphaned word on the last line of the summary or any bullet', function () {
    if (! pdftotextAvailable()) {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    // Bullets of steadily increasing length, so some natural wraps land
    // a lone word on the final line unless the tail is kept together.
    $words = explode(' ', 'Engineered a deterministic retrieval pipeline that maps every generated answer to its exact source section while keeping model synthesis out of the scoring path entirely');
    $bullets = [];
    for ($n = 14; $n <= 29; $n++) {
        $bullets[] = implode(' ', array_slice($words, 0, $n)).' systems.';
    }
    $summary = implode(' ', array_slice($words, 0, 22)).' architecture.';

    $variant = layoutVariant($bullets, $summary);
    $pdf = app(GenerateResumePdf::class)->generate($variant);
    $lines = pdfLines($pdf);

    foreach ([$summary, ...$bullets] as $text) {
        $tokens = preg_split('/\s+/', trim($text));
        $tail = implode(' ', array_slice($tokens, -2));

        $matched = array_filter($lines, fn (string $line) => str_ends_with($line, $tail));

        expect($matched)->not->toBeEmpty("Last two words [{$tail}] must share a final line.");
    }
});

// --- Intentional composition ----------------------------------------------

it('keeps a genuinely two-page resume at two pages instead of squeezing it', function () {
    $pdf = app(GenerateResumePdf::class)->generate(PearlyResumeVariantFixture::generate());

    expect(pdfPages($pdf))->toBe(2);
});

it('turns a small overflow into an intentional one-page resume using compact density', function () {
    $found = null;

    $context = layoutContext();

    for ($count = 8; $count <= 22; $count++) {
        $bullets = array_map(
            fn (int $i) => "Delivered analytics platform increment number {$i}, unifying warehouse and CRM data behind one API to cut reporting time and give teams reliable KPI visibility.",
            range(1, $count),
        );
        $variant = layoutVariant($bullets, context: $context);

        if (pdfPages(standardRender($variant)) === 2 && pdfPages(app(GenerateResumePdf::class)->generate($variant)) === 1) {
            $found = $count;
            break;
        }
    }

    expect($found)->not->toBeNull('Some bullet count must overflow at standard density yet fit one page at compact density.');
});

it('fits an over-long resume into two pages by trimming the lowest-ranked bullets, leaving the stored variant intact', function () {
    $bullets = array_map(fn (int $i) => "Ranked bullet number {$i}: delivered a distinct, relevant platform capability for the business, unifying warehouse and CRM data behind one API so that teams could trust reporting, shorten planning cycles, and act on consistent KPI visibility across every function.", range(1, 40));
    $variant = layoutVariant($bullets);

    $pdf = app(GenerateResumePdf::class)->generate($variant);
    $text = (new PdfParser)->parseContent($pdf)->getText();

    expect(pdfPages($pdf))->toBeLessThanOrEqual(2)
        ->and(fn () => (new ResumePdfValidator)->validate($pdf))->not->toThrow(Throwable::class)
        ->and($text)->toContain('Ranked bullet number 1:')
        ->and($text)->not->toContain('Ranked bullet number 40:')
        ->and($variant->experienceRoles->first()->bullets()->count())->toBe(40);
});

// --- ATS reading order ------------------------------------------------------

it('extracts text in a logical ATS reading order', function () {
    if (! pdftotextAvailable()) {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    $pdf = app(GenerateResumePdf::class)->generate(PearlyResumeVariantFixture::generate());
    $process = new Process(['pdftotext', '-', '-']);
    $process->setInput($pdf)->mustRun();
    $text = $process->getOutput();

    $positions = array_map(fn (string $heading) => strpos($text, $heading), ['TECHNICAL SKILLS', 'PROFESSIONAL EXPERIENCE', 'EDUCATION']);

    expect($positions)->each->not->toBeFalse()
        ->and($positions[0])->toBeLessThan($positions[1])
        ->and($positions[1])->toBeLessThan($positions[2]);
});

it('omits the Technical Skills section entirely when no Skills were selected', function () {
    $pdf = app(GenerateResumePdf::class)->generate(layoutVariant(['Built a deployment pipeline on AWS.']));

    expect((new PdfParser)->parseContent($pdf)->getText())->not->toContain('TECHNICAL SKILLS')
        ->and((new PdfParser)->parseContent($pdf)->getText())->toContain('PROFESSIONAL EXPERIENCE');
});

it('keeps each degree associated with its own institution and year on one line, in every extraction mode', function () {
    $variant = PearlyResumeVariantFixture::generate();
    $variant->educationSelections()->create([
        'education_id' => Education::factory()->create(['career_profile_id' => $variant->career_profile_id])->id,
        'institution' => 'Embry-Riddle Aeronautical University',
        'degree' => 'Bachelor of Science',
        'field_of_study' => 'Engineering Physics',
        'start_year' => 2000,
        'end_year' => 2004,
        'display_order' => 9,
    ]);
    $pdf = app(GenerateResumePdf::class)->generate($variant->fresh());

    $expected = [];
    foreach ($variant->fresh()->educationSelections as $entry) {
        $degree = $entry->degree.($entry->field_of_study ? ', '.$entry->field_of_study : '');
        $expected[] = [$degree, $entry->institution, (string) $entry->end_year];
    }

    // Each entry must be ONE line carrying degree, then institution, then
    // year: this is what rules out column-wise extraction, which would
    // list every degree before any institution.
    $assertEntries = function (array $lines) use ($expected): void {
        foreach ($expected as [$degree, $institution, $year]) {
            $matching = array_values(array_filter($lines, fn (string $line) => preg_match(
                '/^'.preg_quote($degree, '/').'\s*·\s*'.preg_quote($institution, '/').'\s*·\s*'.preg_quote($year, '/').'$/u',
                preg_replace('/[\s\x{00a0}]+/u', ' ', $line),
            ) === 1));

            expect($matching)->toHaveCount(1, "[{$degree}] must share one line with [{$institution}] and [{$year}].");
        }
    };

    // Content-stream order (what most ATS parsers read).
    $assertEntries(explode("\n", (new PdfParser)->parseContent($pdf)->getText()));

    if (! pdftotextAvailable()) {
        return;
    }

    foreach (['default' => ['pdftotext', '-', '-'], 'layout' => ['pdftotext', '-layout', '-', '-']] as $mode => $command) {
        $process = new Process($command);
        $process->setInput($pdf)->mustRun();
        $assertEntries(array_map(fn (string $l) => trim($l), explode("\n", $process->getOutput())));
    }
});

// --- Measured composition: bounds and factual preservation ----------------

function longBullet(int $i): string
{
    return "Ranked bullet number {$i}: delivered a distinct, relevant platform capability for the business, unifying warehouse and CRM data behind one API so teams could trust reporting, shorten planning cycles, and act on consistent KPI visibility across every function.";
}

it('bounds the number of renders and never accepts a third page when optional content can be dropped', function () {
    $variant = layoutVariant(array_map(longBullet(...), range(1, 40)));

    $composed = app(GenerateResumePdf::class)->compose($variant);

    expect($composed->pageCount)->toBeLessThanOrEqual(2)
        ->and($composed->mode)->toBe('trimmed')
        ->and($composed->blocksRemoved)->toBeGreaterThan(0)
        ->and(count($composed->renders))->toBeLessThanOrEqual(GenerateResumePdf::MAX_RENDERS)
        ->and($composed->renders[0])->toMatchArray(['density' => 'standard', 'blocks_removed' => 0]);
});

it('only ever removes the lowest-ranked trailing bullets, never reorders, rewrites, or adds content', function () {
    $bullets = array_map(longBullet(...), range(1, 40));
    $variant = layoutVariant($bullets);

    $composed = app(GenerateResumePdf::class)->compose($variant);
    $text = preg_replace('/\s+/', ' ', (new PdfParser)->parseContent($composed->pdf)->getText());

    $kept = [];
    foreach (range(1, 40) as $i) {
        if (str_contains($text, "Ranked bullet number {$i}:")) {
            $kept[] = $i;
        }
    }

    // The kept bullets are exactly the first N, in order: a pure prefix.
    expect($kept)->toBe(range(1, count($kept)))
        ->and(count($kept))->toBe(40 - $composed->blocksRemoved)
        ->and($text)->not->toContain('Ranked bullet number 41:');
});

it('drops the last Selected Project before any Experience bullet', function () {
    $document = app(GenerateResumeDocument::class)->generate(layoutVariant(['First bullet.', 'Second bullet.']));
    $withProjects = new ResumeDocument(
        $document->contact, $document->summary, $document->experience, $document->skills,
        [
            new ResumeSelectedProject('Alpha', [], 'Alpha bullet.', null, null),
            new ResumeSelectedProject('Beta', [], 'Beta bullet.', null, null),
        ],
        $document->education,
    );

    $step1 = $withProjects->withoutLowestPriorityBlock();
    $step2 = $step1->withoutLowestPriorityBlock();
    $step3 = $step2->withoutLowestPriorityBlock();

    expect(array_column($step1->selectedProjects, 'name'))->toBe(['Alpha'])
        ->and($step2->selectedProjects)->toBe([])
        ->and($step2->experience[0]->bullets)->toHaveCount(2)
        ->and($step3->experience[0]->bullets)->toHaveCount(1)
        // Employment history and education are never removed.
        ->and($step3->education)->toBe($document->education)
        ->and($step3->withoutLowestPriorityBlock())->toBeNull();
});

it('reports the over-length render, rather than looping or deleting history, when nothing optional remains', function () {
    $bullets = array_map(fn ($i) => longBullet($i), range(1, 40));
    $candidate = layoutContext();
    // 40 single-bullet roles: there is no optional block to drop.
    $variant = layoutVariant([longBullet(1)], context: $candidate);
    for ($i = 2; $i <= 40; $i++) {
        $role = $variant->experienceRoles()->create([
            'role_id' => $candidate[0]['role']->id, 'employer_name' => 'Acme', 'display_title' => "Engineer {$i}",
            'start_year' => 2000, 'start_month' => 1, 'end_year' => 2001, 'end_month' => 1, 'display_order' => $i,
        ]);
        $role->bullets()->create(['resume_variant_id' => $variant->id, 'display_order' => 1, 'text' => longBullet($i)]);
    }

    $composed = app(GenerateResumePdf::class)->compose($variant->fresh());

    expect($composed->pageCount)->toBeGreaterThan(2)
        ->and($composed->blocksRemoved)->toBe(0)
        ->and(count($composed->renders))->toBeLessThanOrEqual(GenerateResumePdf::MAX_RENDERS)
        ->and(fn () => (new ResumePdfValidator)->validate($composed->pdf))->toThrow(ResumePdfPageBudgetExceededException::class);
});

it('records measured diagnostics for a one-page render', function () {
    $composed = app(GenerateResumePdf::class)->compose(layoutVariant(['Built a deployment pipeline on AWS.']));

    expect($composed->pageCount)->toBe(1)
        ->and($composed->mode)->toBe('standard')
        ->and($composed->renders)->toHaveCount(1)
        ->and($composed->renderMs)->toBeGreaterThan(0)
        ->and($composed->diagnostics())->toHaveKeys(['mode', 'pages', 'blocks_removed', 'render_count', 'renders', 'last_page_fill']);

    if (pdftotextAvailable()) {
        expect($composed->lastPageFill)->toBeGreaterThan(0.0)->toBeLessThan(0.5);
    }
});

// --- The PDF pipeline stage -------------------------------------------------

it('renders, validates, caches, and records timing and layout diagnostics in the PDF stage', function () {
    $variant = layoutVariant(['Built a deployment pipeline on AWS.']);
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => 'resume_variant',
        'subject_id' => $variant->id,
        'generation_type' => GenerationType::ResumePdf,
        'status' => GenerationStatus::Queued,
        'queued_at' => now(),
    ]);

    (new RenderResumePdfJob($attempt))->handle(
        app(GenerateResumePdf::class),
        app(GenerateResumeDocument::class),
        app(ResumePdfStore::class),
        app(ResumePdfValidator::class),
    );

    $fresh = $attempt->fresh();
    $document = app(GenerateResumeDocument::class)->generate($variant);

    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->result_id)->toBe($variant->id)
        ->and($fresh->pdf_page_count)->toBe(1)
        ->and($fresh->layout_mode)->toBe('standard')
        ->and($fresh->render_ms)->toBeGreaterThan(0)
        ->and($fresh->duration_ms)->toBeInt()
        ->and($fresh->layout_diagnostics['render_count'])->toBe(1)
        // The page-balance judgment is recorded with the measurements, so a short last page is never just a page count.
        ->and($fresh->layout_diagnostics['quality']['status'])->toBe('one_page')
        ->and(app(ResumePdfStore::class)->get($document))->toStartWith('%PDF-');
});

it('serves the cached PDF to the download route without re-rendering', function () {
    $variant = layoutVariant(['Built a deployment pipeline on AWS.']);
    $document = app(GenerateResumeDocument::class)->generate($variant);
    app(ResumePdfStore::class)->put($document, '%PDF-1.4 cached-sentinel');

    $response = $this->get(route('resume-variants.pdf', $variant));

    $response->assertOk();
    expect($response->getContent())->toBe('%PDF-1.4 cached-sentinel');
});

it('fails the PDF stage with a useful category when the page budget cannot be met', function () {
    $context = layoutContext();
    $variant = layoutVariant([longBullet(1)], context: $context);
    for ($i = 2; $i <= 40; $i++) {
        $role = $variant->experienceRoles()->create([
            'role_id' => $context[0]['role']->id, 'employer_name' => 'Acme', 'display_title' => "Engineer {$i}",
            'start_year' => 2000, 'start_month' => 1, 'end_year' => 2001, 'end_month' => 1, 'display_order' => $i,
        ]);
        $role->bullets()->create(['resume_variant_id' => $variant->id, 'display_order' => 1, 'text' => longBullet($i)]);
    }
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => 'resume_variant', 'subject_id' => $variant->id,
        'generation_type' => GenerationType::ResumePdf, 'status' => GenerationStatus::Queued, 'queued_at' => now(),
    ]);

    (new RenderResumePdfJob($attempt))->handle(
        app(GenerateResumePdf::class), app(GenerateResumeDocument::class),
        app(ResumePdfStore::class), app(ResumePdfValidator::class),
    );

    expect($attempt->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($attempt->fresh()->failure_category)->toBe('pdf_page_budget')
        ->and($attempt->fresh()->failure_message)->toContain('pages');
});

// --- Page-balance judgment on real renders -----------------------------------

function layoutBullet(int $i): string
{
    return "Delivered analytics platform increment number {$i}, unifying warehouse and CRM data behind one API to cut reporting time and give teams reliable KPI visibility.";
}

/**
 * Composes resumes of growing length until one is two pages with a nearly empty last page and one is two pages with a
 * well-filled last page, so the judgment is checked against the real layout rather than a number.
 *
 * @return array{short: ?ComposedResumePdf, full: ?ComposedResumePdf}
 */
function growingLayouts(): array
{
    $context = layoutContext();
    $found = ['short' => null, 'full' => null];

    for ($count = 10; $count <= 40; $count++) {
        $composed = app(GenerateResumePdf::class)->compose(layoutVariant(array_map(layoutBullet(...), range(1, $count)), context: $context));

        if ($composed->pageCount === 2 && $composed->lastPageFill !== null) {
            $found['short'] ??= $composed->lastPageFill < 0.40 ? $composed : null;

            if ($composed->lastPageFill >= 0.55) {
                $found['full'] = $composed;
                break;
            }
        }
    }

    return $found;
}

it('judges a balanced two-page resume balanced and a nearly empty second page a defect, from the real render', function () {
    if (! pdftotextAvailable()) {
        $this->markTestSkipped('pdftotext is not installed.');
    }

    $layouts = growingLayouts();

    expect($layouts['short'])->not->toBeNull('Some length must produce a two-page resume with a second page under 40% full.')
        ->and($layouts['full'])->not->toBeNull('Some length must produce a two-page resume with a second page at least 55% full.');

    $short = $layouts['short']->quality;
    $full = $layouts['full']->quality;

    expect($short['status'])->toBe('underfilled')->and($short['severity'])->toBe('review')->and($short['fill'])->toBe($layouts['short']->lastPageFill)
        ->and($short['summary'])->toContain('% full')
        // The judgment travels with the measurements.
        ->and($layouts['short']->diagnostics()['quality'])->toBe($short)
        ->and($full['status'])->toBe('balanced')->and($full['severity'])->not->toBe('review');
});

it('does not change the PDF it judges: the same document renders to the same bytes', function () {
    $variant = layoutVariant(array_map(layoutBullet(...), range(1, 14)));
    $first = app(GenerateResumePdf::class)->compose($variant);
    $second = app(GenerateResumePdf::class)->compose($variant);

    expect($first->quality)->not->toBeNull()->and($first->pageCount)->toBe($second->pageCount)->and($first->lastPageFill)->toBe($second->lastPageFill)
        ->and($first->mode)->toBe($second->mode)->and(pdfPages($first->pdf))->toBe($first->pageCount);
});

it('judges a legitimate one-page resume as one page, never a defect, and places nothing', function () {
    $composed = app(GenerateResumePdf::class)->compose(layoutVariant(['Built a deployment pipeline on AWS.']));

    expect($composed->pageCount)->toBe(1)->and($composed->quality['status'])->toBe('one_page')->and($composed->quality['severity'])->toBeNull()
        ->and($composed->quality['findings'])->toBe([])->and($composed->placement)->toBeNull();
});

it('describes an employer that straddles the page break by where its bullets fell', function () {
    $variant = layoutVariant(array_map(layoutBullet(...), range(1, 21)));
    $composed = app(GenerateResumePdf::class)->compose($variant);

    expect($composed->pageCount)->toBe(2)->and($composed->placement)->toHaveCount(1);

    $role = $composed->placement[0];

    // Every bullet is accounted for, on exactly the pages it is on, and the employer is reported as split.
    expect(array_sum($role['by_page']))->toBe(21)->and(array_keys($role['by_page']))->toBe([1, 2])
        ->and(array_column($composed->quality['findings'], 'code'))->toContain(count(array_filter($role['by_page'], fn ($n) => $n === 1)) > 0 && $role['bullets'] >= 3 ? 'role_split_awkward' : 'role_split');

    // Bullets are found by the text layer: the page each lands on is the page its text is actually on.
    $pageTwoText = preg_replace('/[^a-z0-9]+/', '', strtolower((new PdfParser)->parseContent($composed->pdf)->getPages()[1]->getText()));
    $lastBullet = preg_replace('/[^a-z0-9]+/', '', strtolower(mb_substr(layoutBullet(21), 0, 60)));

    expect(str_contains($pageTwoText, $lastBullet))->toBeTrue();
});

it('describes a trimmed over-long resume by what was rendered, not by what was stored', function () {
    $variant = layoutVariant(array_map(longBullet(...), range(1, 40)));
    $composed = app(GenerateResumePdf::class)->compose($variant);

    // Bullets that were dropped to fit are not looked for in the PDF, so they cannot make the placement unmeasurable.
    expect($composed->blocksRemoved)->toBeGreaterThan(0)->and($composed->placement)->not->toBeNull()
        ->and(array_sum($composed->placement[0]['by_page']))->toBe(40 - $composed->blocksRemoved);
});
