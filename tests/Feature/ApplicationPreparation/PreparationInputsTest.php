<?php

use App\Models\AgentRun;
use App\Models\CareerProfile;
use App\Models\WorkflowRun;
use App\Support\ApplicationPreparation\BuildPreparationApplicant;
use App\Support\ApplicationPreparation\DispatchApplicationPreparation;
use App\Support\ApplicationPreparation\PreparationArtifacts;
use App\Support\ApplicationPreparation\PreparationException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

function prepApplicantFor(array $attributes): array
{
    return app(BuildPreparationApplicant::class)->build(new CareerProfile($attributes));
}

it('sends first and last name only when the name is unambiguous', function (string $name, bool $split) {
    $result = prepApplicantFor(['name' => $name]);

    expect($result['applicant']['full_name'])->toBe($name);
    if ($split) {
        expect($result['applicant'])->toHaveKeys(['first_name', 'last_name'])
            ->and($result['applicant']['first_name'].' '.$result['applicant']['last_name'])->toBe($name);
    } else {
        expect($result['applicant'])->not->toHaveKeys(['first_name', 'last_name'])
            ->and($result['omitted']['first_name'])->toBe('ambiguous_name')
            ->and($result['omitted']['last_name'])->toBe('ambiguous_name');
    }
})->with([
    'two plain words' => ['Alex Example', true],
    'hyphenated surname' => ['Alex Example-Test', true],
    'apostrophe' => ["Alex O'Example", true],
    'three words' => ['Mary Jo Smith', false],
    'comma' => ['Example, Alex', false],
    'title' => ['Dr. Alex', false],
    'initial' => ['A. Example', false],
    'a single word' => ['Alex', false],
    'with a suffix' => ['Alex Example Jr.', false],
    'with digits' => ['Alex3 Example', false],
]);

it('never invents LinkedIn, current company or any missing detail', function () {
    $result = prepApplicantFor(['name' => 'Alex Example']);

    expect($result['applicant'])->toBe(['first_name' => 'Alex', 'full_name' => 'Alex Example', 'last_name' => 'Example'])
        ->and($result['omitted'])->toBe([
            'current_company' => 'no_source', 'email' => 'missing', 'github_url' => 'missing', 'linkedin_url' => 'no_source',
            'location' => 'missing', 'phone' => 'missing', 'portfolio_url' => 'missing',
        ]);
});

it('leaves out a detail that is not plausibly what its key means, saying why', function () {
    $result = prepApplicantFor([
        'name' => 'Alex Example',
        'email' => 'not an email',
        'phone' => 'call me',
        'github_url' => 'http://github.com/x',
        'portfolio_url' => 'https://user:pw@example.test',
        'location' => str_repeat('x', 300),
    ]);

    expect($result['applicant'])->not->toHaveKeys(['email', 'phone', 'github_url', 'portfolio_url', 'location'])
        ->and($result['omitted'])->toMatchArray(['email' => 'invalid', 'phone' => 'invalid', 'github_url' => 'invalid', 'portfolio_url' => 'invalid', 'location' => 'invalid']);
});

it('normalises whitespace but changes nothing else about a value', function () {
    $result = prepApplicantFor(['name' => '  Alex   Example ', 'email' => ' alex.example@example.test ', 'phone' => '+1 (555) 010-0199']);

    expect($result['applicant'])->toMatchArray(['full_name' => 'Alex Example', 'email' => F::EMAIL, 'phone' => '+1 (555) 010-0199']);
});

// ---- the snapshot ----------------------------------------------------------------------------------------------

it('keeps the resume write-once: a different file under the same hash name is impossible, an identical rewrite is a no-op', function () {
    ['run' => $run] = F::queuedRun();
    $artifacts = app(PreparationArtifacts::class);

    $again = $artifacts->writeResume($run, F::PDF);
    expect($again['sha256'])->toBe(hash('sha256', F::PDF))
        ->and($artifacts->readResume($run))->toBe(F::PDF);

    // A second, different resume for the same attempt is a different file; the first is never overwritten.
    $artifacts->writeResume($run, F::PDF_OTHER);
    expect($artifacts->readResume($run))->toBe(F::PDF)
        ->and(Storage::disk('local')->files(PreparationArtifacts::ROOT."/{$run->id}"))->toHaveCount(3);
});

it('refuses to overwrite a snapshot whose bytes differ', function () {
    ['run' => $run] = F::queuedRun();
    $path = PreparationArtifacts::ROOT."/{$run->id}/resume-".hash('sha256', F::PDF).'.pdf';
    Storage::disk('local')->put($path, 'tampered');

    expect(fn () => app(PreparationArtifacts::class)->writeResume($run, F::PDF))->toThrow(PreparationException::class);
    expect(Storage::disk('local')->get($path))->toBe('tampered');
});

it('verifies the SHA-256 on every read: a damaged or replaced file reads as unavailable', function () {
    ['run' => $run] = F::queuedRun();
    $artifacts = app(PreparationArtifacts::class);
    $dir = PreparationArtifacts::ROOT."/{$run->id}";

    expect($artifacts->readInput($run))->toBeArray()->and($artifacts->readResume($run))->toBe(F::PDF);

    Storage::disk('local')->put($dir.'/resume-'.hash('sha256', F::PDF).'.pdf', F::PDF_OTHER);
    Storage::disk('local')->put($dir.'/input.json', '{"applicant":{"email":"someone-else@example.test"}}');

    expect($artifacts->readResume($run))->toBeNull()
        ->and($artifacts->readInput($run))->toBeNull();
});

it('refuses non-PDF and oversized resume bytes', function () {
    ['run' => $run] = F::queuedRun();
    $artifacts = app(PreparationArtifacts::class);

    expect(fn () => $artifacts->writeResume($run, 'plain text'))->toThrow(PreparationException::class)
        ->and(fn () => $artifacts->writeResume($run, '%PDF-'.str_repeat('x', PreparationArtifacts::MAX_RESUME_BYTES)))->toThrow(PreparationException::class);
});

it('keeps every attempt\'s files in its own private directory, never on a public path', function () {
    Storage::fake('public');
    ['run' => $a] = F::queuedRun();
    AgentRun::query()->update(['status' => 'failed']);
    WorkflowRun::query()->update(['status' => 'failed']);
    $job = $a->workflowStep->workflowRun->application->jobPosting;
    app(DispatchApplicationPreparation::class)->dispatch($job);
    $b = AgentRun::latest('id')->first();

    $artifacts = app(PreparationArtifacts::class);
    expect($a->id)->not->toBe($b->id)
        ->and($artifacts->directory($a))->not->toBe($artifacts->directory($b))
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(collect(Storage::disk('local')->allFiles())->every(fn ($f) => str_starts_with($f, PreparationArtifacts::ROOT.'/') || str_starts_with($f, 'resume-pdfs/')))->toBeTrue();
});
