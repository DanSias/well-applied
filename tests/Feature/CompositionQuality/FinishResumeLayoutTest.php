<?php

use App\Contracts\GeneratesResumeWording;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Exceptions\ResumeGenerationProviderException;
use App\Jobs\RenderResumePdfJob;
use App\Models\GenerationAttempt;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Support\ResumeDocument\AssessResumeLayout;
use App\Support\ResumeDocument\ComposedResumePdf;
use App\Support\ResumeDocument\GenerateResumeDocument;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfStore;
use App\Support\ResumeDocument\ResumePdfValidator;
use App\Support\ResumeRevision\FinishResumeLayout;
use App\Support\ResumeRevision\JobResumes;
use App\Support\ResumeVariant\GenerateResumeVariant;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use Tests\Support\CompositionScenario;
use Tests\Support\ScriptedPdfRenderer;

/**
 * The one bounded corrective pass for an underfilled last page. Evidence is invented, the model is a scripted double and the
 * renderer reports the layouts a test decides (with a real PDF as its payload), so every decision is deterministic.
 */
const FIN_ORIGINAL = ['pages' => 2, 'fill' => 0.25];
const FIN_BETTER = ['pages' => 2, 'fill' => 0.62];

/**
 * A model double for the additions call. `$texts` maps a fact key to the bullet to return for the addition that cites it;
 * by default a bullet is the addition's own statements.
 */
function additionModel(stdClass $seen, array $texts = []): GeneratesResumeWording
{
    return new class($seen, $texts) implements GeneratesResumeWording
    {
        public function __construct(private stdClass $seen, private array $texts) {}

        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            $this->seen->calls = ($this->seen->calls ?? 0) + 1;
            preg_match('/--- Missing accomplishments ---\n(.*?)\n--- End of missing accomplishments ---/s', $user, $m);
            $this->seen->input = $input = json_decode($m[1], true);
            $this->seen->system = $system;
            $this->seen->schema = $schema;

            if (isset($this->seen->fail)) {
                throw $this->seen->fail;
            }

            return new ResumeWordingProviderResponse('fake', 'stub', ['bullets' => array_map(function (array $a) {
                $statements = array_column($a['facts'], 'statement');

                return ['id' => $a['id'], 'text' => $this->texts[$a['facts'][0]['statement']] ?? implode(' ', array_map(fn ($s) => rtrim($s, '.').'.', $statements))];
            }, $input['additions'])]);
        }
    };
}

/**
 * A first draft with little on it and relevant Public evidence left off, a scripted renderer, and the first render measured.
 *
 * @param  array<int, array<string, mixed>>  $script  layouts after the first render (the corrected resume's)
 * @return array{0: array<string, mixed>, 1: ResumeVariant, 2: stdClass, 3: array<string, mixed>, 4: ComposedResumePdf}
 */
function underfilledDraft(array $script = [FIN_BETTER], array $first = FIN_ORIGINAL, array $texts = []): array
{
    config(['resume.composition.bullets.min' => 2, 'resume.composition.bullets.target' => 2]);
    $s = CompositionScenario::create();
    $synthesis = CompositionScenario::approvedProfile($s);
    app()->instance(GeneratesResumeWording::class, CompositionScenario::wording(new stdClass));
    $variant = app(GenerateResumeVariant::class)->generateForJob($s['analysis'], $synthesis);

    $renderer = new ScriptedPdfRenderer(app(GenerateResumeDocument::class), [$first, ...$script]);
    app()->instance(GenerateResumePdf::class, $renderer);
    $seen = new stdClass;
    app()->instance(GeneratesResumeWording::class, additionModel($seen, $texts));

    $composed = $renderer->compose($variant);
    $diagnostics = [...$composed->diagnostics(), 'quality' => app(AssessResumeLayout::class)->forVariant($variant, $composed)];

    return [$s, $variant, $seen, $diagnostics, $composed];
}

function fingerprint(ResumeVariant $variant): string
{
    return md5(json_encode([
        ResumeVariant::find($variant->id)->only(['summary', 'review_report', 'selection_raw_response', 'edit_log', 'parent_variant_id']),
        ResumeVariantExperienceBullet::where('resume_variant_id', $variant->id)->orderBy('id')->get(['id', 'text', 'display_order'])->toArray(),
    ]));
}

it('makes no correction for a balanced two-page resume', function () {
    [, $variant, $seen, $diagnostics, $composed] = underfilledDraft(first: ['pages' => 2, 'fill' => 0.7]);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBe('not_needed')->and($result['correction']['requested'])->toBeFalse()
        ->and($seen->calls ?? 0)->toBe(0)->and(ResumeVariant::count())->toBe(1)
        ->and(array_column($result['quality']['findings'], 'code'))->not->toContain('correction_applied', 'correction_not_kept');
});

it('makes no correction for a legitimate one-page resume', function () {
    [, $variant, $seen, $diagnostics, $composed] = underfilledDraft(first: ['pages' => 1, 'fill' => 0.5]);

    expect($diagnostics['quality']['status'])->toBe('one_page')
        ->and(app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics)['correction']['outcome'])->toBe('not_needed')->and($seen->calls ?? 0)->toBe(0);
});

it('makes no correction for a last page that is only a little short', function () {
    [, $variant, $seen, $diagnostics, $composed] = underfilledDraft(first: ['pages' => 2, 'fill' => 0.48]);

    expect($diagnostics['quality']['status'])->toBe('light')
        ->and(app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics)['correction']['outcome'])->toBe('not_needed')
        ->and($seen->calls ?? 0)->toBe(0);
});

it('adds relevant unused evidence to an underfilled second page, as a new version that keeps the original', function () {
    [$s, $variant, $seen, $diagnostics, $composed] = underfilledDraft();
    $before = fingerprint($variant);
    $originalDocument = app(GenerateResumeDocument::class)->generate($variant);
    app(ResumePdfStore::class)->put($originalDocument, $composed->pdf);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);
    $correction = $result['correction'];
    $derived = ResumeVariant::find($correction['variant_id']);
    $derived->load('experienceRoles.bullets.citations.careerFact');
    $added = $derived->experienceRoles->flatMap(fn ($r) => $r->bullets->flatMap(fn ($b) => $b->citations->map(fn ($c) => $c->careerFact->key)))->all();

    expect($correction['outcome'])->toBe('applied')->and($correction['before']['last_page_fill'])->toBe(0.25)->and($correction['after']['last_page_fill'])->toBe(0.62)
        // One narrow model call, for the additions only.
        ->and($seen->calls)->toBe(1)->and(array_column($seen->input['additions'], 'id'))->toBe([1, 2])
        ->and($seen->schema['properties']['bullets']['maxItems'])->toBe(2)
        // A new derived version that cites the added evidence, links to its parent and says how it was made.
        ->and($derived->parent_variant_id)->toBe($variant->id)->and($derived->wording_generated_by)->toBe('layout-correction')
        ->and($added)->toContain('recurring', 'b-what', 'webhooks')->not->toContain('private', 'restricted-figure', 'first-person', 'trivia')
        ->and($derived->edit_log['correction']['selected'][0]['reasons'])->not->toBeEmpty()
        ->and(app(JobResumes::class)->current($s['posting'])->id)->toBe($derived->id)
        // The original is exactly as it was, with its PDF.
        ->and(fingerprint($variant))->toBe($before)->and(app(ResumePdfStore::class)->get($originalDocument))->not->toBeNull()
        ->and(array_column($result['quality']['findings'], 'code'))->toContain('correction_applied');
});

it('chooses additions for relevance and distinct contribution, never marketing, restricted or low-relevance filler, and says why', function () {
    [$s, $variant, , $diagnostics, $composed] = underfilledDraft();

    $correction = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics)['correction'];
    $keys = array_merge(...array_column($correction['selected'], 'fact_keys'));

    expect($keys)->toBe(['recurring', 'b-what'])->and($keys)->not->toContain('trivia', 'b-owned')
        ->and(collect($correction['selected'])->every(fn ($a) => $a['relevance'] >= config('resume.finishing.min_relevance')))->toBeTrue()
        ->and($correction['selected'][1]['reasons'])->toContain('adds a project the resume does not yet represent')
        // Fewer than the gap's estimate (6 bullets): only evidence worth adding is added.
        ->and(count($correction['selected']))->toBeLessThan($diagnostics['quality']['room']['bullets_to_balance']);
});

it('reports honestly, and changes nothing, when relevant evidence is exhausted', function () {
    config(['resume.finishing.min_relevance' => 0.99]);
    [, $variant, $seen, $diagnostics, $composed] = underfilledDraft();
    $before = fingerprint($variant);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBe('no_evidence')->and($result['correction']['message'])->toContain('honest length')
        ->and($seen->calls ?? 0)->toBe(0)->and(ResumeVariant::count())->toBe(1)->and(fingerprint($variant))->toBe($before)
        // The page is not called fine: it is still judged underfilled for a reader.
        ->and($result['quality']['status'])->toBe('underfilled')->and($result['quality']['severity'])->toBe('review');
});

it('refuses a correction that would run onto a third page, only fit by trimming, or sit on the edge of another page', function (array $corrected) {
    [, $variant, , $diagnostics, $composed] = underfilledDraft([$corrected]);
    $before = fingerprint($variant);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBeIn(['validation_failed', 'pages'])
        ->and(ResumeVariant::count())->toBe(1)->and(fingerprint($variant))->toBe($before)
        ->and(array_column($result['quality']['findings'], 'code'))->toContain('correction_not_kept');
})->with([
    'third page' => [['pages' => 3, 'fill' => 0.1]],
    'trimmed to fit' => [['pages' => 2, 'fill' => 0.6, 'mode' => 'trimmed']],
    'nearly full' => [['pages' => 2, 'fill' => 0.97]],
]);

it('refuses additions that repeat what is already on the resume', function () {
    // The Nimbus bullet already states an 85% result; the model words an addition around the same figure.
    [, $variant, , $diagnostics, $composed] = underfilledDraft(texts: ['Set up recurring billing for merchants through the payment gateway API.' => 'Set up recurring billing through the payment gateway API, cutting reporting time by 85%.']);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBe('duplicate')->and($result['correction']['message'])->toContain('85%')->and(ResumeVariant::count())->toBe(1);
});

it('keeps nothing when an added bullet fails the claim and technology checks', function () {
    // The recurring-billing fact names no front-end framework; a bullet that claims React from it is not allowed.
    [, $variant, , $diagnostics, $composed] = underfilledDraft(texts: ['Set up recurring billing for merchants through the payment gateway API.' => 'Built a React dashboard for recurring billing through the payment gateway API.']);
    $before = fingerprint($variant);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBe('validation_failed')->and($result['correction']['message'])->toContain('claim checks')
        ->and(ResumeVariant::count())->toBe(1)->and(fingerprint($variant))->toBe($before)
        ->and(ResumeVariant::whereNotNull('parent_variant_id')->count())->toBe(0)
        // The rejected render is not left in the PDF cache.
        ->and(Storage::disk('local')->allFiles('resume-pdfs'))->toBe([]);
});

it('does not keep a correction that is not meaningfully fuller', function () {
    [, $variant, , $diagnostics, $composed] = underfilledDraft([['pages' => 2, 'fill' => 0.28]]);

    expect(app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics)['correction']['outcome'])->toBe('not_better')->and(ResumeVariant::count())->toBe(1);
});

it('does not keep a correction that strands a single bullet across a page break', function () {
    $stranded = [['employer' => 'RocketGate', 'title' => 'Developer Support Engineer', 'bullets' => 4, 'by_page' => [1 => 3, 2 => 1]]];
    [, $variant, , $diagnostics, $composed] = underfilledDraft([['pages' => 2, 'fill' => 0.62, 'placement' => $stranded]]);

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBe('not_better')->and($result['correction']['message'])->toContain('stranded')->and(ResumeVariant::count())->toBe(1);
});

it('prefers to grow the side of an employer split that has a bullet stranded', function () {
    [, $variant, , $diagnostics, $composed] = underfilledDraft(first: ['pages' => 2, 'fill' => 0.25, 'placement' => [['employer' => 'RocketGate', 'title' => 'Developer Support Engineer', 'bullets' => 3, 'by_page' => [1 => 2, 2 => 1]]]]);

    $correction = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics)['correction'];

    expect(collect($correction['selected'])->flatMap(fn ($a) => $a['reasons'])->all())->toContain('grows the side of an employer split that has a single bullet stranded');
});

it('makes at most one correction: a derived version is never finished again', function () {
    [$s, $variant, , $diagnostics, $composed] = underfilledDraft();
    $correction = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics)['correction'];
    $derived = ResumeVariant::find($correction['variant_id']);

    $again = app(FinishResumeLayout::class)->decide($derived, ['status' => 'underfilled']);

    expect($again['request'])->toBeFalse()->and($again['outcome'])->toBe('not_eligible');
});

it('leaves the resume as it was, and says so, when the model is unavailable', function () {
    [, $variant, $seen, $diagnostics, $composed] = underfilledDraft();
    $seen->fail = new ResumeGenerationProviderException('model unavailable');

    $result = app(FinishResumeLayout::class)->finish($variant, $composed, $diagnostics);

    expect($result['correction']['outcome'])->toBe('wording_unavailable')->and($seen->calls)->toBe(1)->and(ResumeVariant::count())->toBe(1)
        ->and(array_column($result['quality']['findings'], 'code'))->toContain('correction_not_kept');
});

function renderAttempt(ResumeVariant $variant): GenerationAttempt
{
    return GenerationAttempt::factory()->create([
        'subject_type' => 'resume_variant', 'subject_id' => $variant->id, 'generation_type' => GenerationType::ResumePdf, 'status' => GenerationStatus::Queued, 'queued_at' => now(),
    ]);
}

it('runs after the first render of a real job and keeps the first render\'s own diagnostics', function () {
    [, $variant] = underfilledDraft();
    $renderer = new ScriptedPdfRenderer(app(GenerateResumeDocument::class), [FIN_ORIGINAL, FIN_BETTER]);
    app()->instance(GenerateResumePdf::class, $renderer);
    $attempt = renderAttempt($variant);

    (new RenderResumePdfJob($attempt))->handle($renderer, app(GenerateResumeDocument::class), app(ResumePdfStore::class), app(ResumePdfValidator::class));
    $fresh = $attempt->fresh();

    expect($fresh->status)->toBe(GenerationStatus::Succeeded)->and($fresh->result_id)->toBe($variant->id)
        // The first render's own measurements are what the attempt records.
        ->and($fresh->pdf_page_count)->toBe(2)->and($fresh->layout_diagnostics['last_page_fill'])->toBe(0.25)->and($fresh->layout_diagnostics['quality']['status'])->toBe('underfilled')
        ->and($fresh->layout_diagnostics['correction']['outcome'])->toBe('applied')->and($fresh->layout_diagnostics['correction']['variant_id'])->not->toBe($variant->id)
        // The first render and one render of the corrected version: no more.
        ->and($renderer->calls)->toBe(2);
});

it('records an error inside the correction and still finishes the render with the first PDF', function () {
    [, $variant] = underfilledDraft();
    app()->instance(GeneratesResumeWording::class, new class implements GeneratesResumeWording
    {
        public function generate(string $system, string $user, array $schema): ResumeWordingProviderResponse
        {
            throw new RuntimeException('boom');
        }
    });
    $renderer = new ScriptedPdfRenderer(app(GenerateResumeDocument::class), [FIN_ORIGINAL]);
    app()->instance(GenerateResumePdf::class, $renderer);
    $attempt = renderAttempt($variant);

    (new RenderResumePdfJob($attempt))->handle($renderer, app(GenerateResumeDocument::class), app(ResumePdfStore::class), app(ResumePdfValidator::class));
    $fresh = $attempt->fresh();

    expect($fresh->status)->toBe(GenerationStatus::Succeeded)->and($fresh->layout_diagnostics['correction']['outcome'])->toBe('error')
        ->and($fresh->layout_diagnostics['quality']['status'])->toBe('underfilled')->and(ResumeVariant::count())->toBe(1);
});
