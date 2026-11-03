<?php

use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\PresentationPreference;
use App\Support\CareerData\ApprovedEvidenceFile;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\CurrentCareerProfile;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ClarificationFixtures;

/**
 * @return array<string, mixed>
 */
function approvedScenario(): array
{
    $s = ClarificationFixtures::scenario();
    app(ClarificationGate::class)->pauseIfNeeded($s['posting'], $s['analysis'], $s['match'], 'run');
    app(ClarificationGate::class)->deepen(ClarificationSession::sole());
    $submit = app(SubmitClarificationAnswer::class);
    $review = app(ReviewEvidenceProposal::class);
    $question = fn (string $topic) => ClarificationQuestion::where('topic_key', $topic)->firstOrFail();

    $s['approvedFact'] = $review->approve(
        $submit->submit($question('webhooks'), 'experience', ['configured', 'advised'], 'Configured webhooks for merchant retry schedules.'),
        ['visibility' => 'public'],
    )->careerFact;
    $review->approve($submit->submit($question('ruby_on_rails'), 'no_experience', [], ''), []);
    $review->approve($submit->submit($question('recurring_billing'), 'preference', [], 'Emphasize conversion work where relevant.'), []);

    return $s;
}

function exportFile(): string
{
    return app(ApprovedEvidenceFile::class)->encode(app(ApprovedEvidenceFile::class)->export(CurrentCareerProfile::resolve()));
}

it('exports approved facts, limitations and preferences only, never proposals or rejections', function () {
    $s = approvedScenario();
    // A proposal that is still undecided, and one that was rejected.
    $open = app(SubmitClarificationAnswer::class);
    app(ClarificationGate::class)->pauseIfNeeded(...[$s['posting'], $s['analysis'], $s['match'], 'x']);
    $extra = ClarificationQuestion::where('topic_key', 'webhooks')->firstOrFail();
    $pending = $open->submit($extra, 'experience', ['configured'], 'A different undecided webhook claim.');
    $rejected = $open->submit($extra, 'experience', ['configured'], 'A rejected webhook claim.');
    app(ReviewEvidenceProposal::class)->reject($rejected);

    $json = exportFile();
    $data = json_decode($json, true);

    expect($data['_meta']['format'])->toBe('career-toolkit.approved-evidence')
        ->and(collect($data['facts'])->pluck('key')->all())->toBe([$s['approvedFact']->key])
        ->and($data['facts'][0]['attributable'])->toBe(['type' => 'role', 'employer' => 'RocketGate', 'title' => 'Developer Support Engineer', 'start_year' => 2025, 'start_month' => 5])
        ->and($data['facts'][0]['evidence'][0]['quoted_text'])->toBe('Configured webhooks for merchant retry schedules.')
        ->and($data['facts'][0]['evidence'][0]['metadata'])->toBe(['origin' => 'clarification', 'topic_key' => 'webhooks', 'scopes' => ['configured', 'advised'], 'revision' => 1])
        ->and($data['limitations'][0]['topic_key'])->toBe('ruby_on_rails')
        ->and($data['presentation_preferences'][0]['guidance'])->toBe('Emphasize conversion work where relevant.')
        ->and($json)->not->toContain('undecided webhook claim')->not->toContain('rejected webhook claim')
        // Original facts from the base dataset are not part of this file.
        ->and($json)->not->toContain($s['publicFact']->key);
});

it('exports byte-identically every time, and writes the file only when it changed', function () {
    approvedScenario();
    $path = sys_get_temp_dir().'/approved-evidence-'.uniqid().'.json';

    expect(exportFile())->toBe(exportFile());

    Artisan::call('career:export-approved', ['path' => $path]);
    $first = file_get_contents($path);
    $mtime = filemtime($path);
    Artisan::call('career:export-approved', ['path' => $path]);

    expect(Artisan::output())->toContain('Already up to date')->and(file_get_contents($path))->toBe($first)->and(filemtime($path))->toBe($mtime);
    unlink($path);
});

it('syncs the file back into an empty corpus, then is a no-op the second time', function () {
    $s = approvedScenario();
    $json = exportFile();
    $key = $s['approvedFact']->key;
    $s['approvedFact']->evidence()->delete();
    $s['approvedFact']->delete();
    CareerLimitation::query()->delete();
    PresentationPreference::query()->delete();
    $revision = EvidenceRevision::current($s['profile']);

    $first = app(ApprovedEvidenceFile::class)->sync($s['profile'], json_decode($json, true));
    $second = app(ApprovedEvidenceFile::class)->sync($s['profile'], json_decode($json, true));

    $fact = CareerFact::where('key', $key)->firstOrFail();
    expect($first['created'])->toBe(3)->and($first['conflicts'])->toBe([])
        ->and($second)->toMatchArray(['created' => 0, 'replaced' => 0, 'conflicts' => []])->and($second['unchanged'])->toBe(3)
        ->and($fact->visibility)->toBe(Visibility::Public)
        ->and($fact->evidence()->count())->toBe(1)
        ->and(CareerLimitation::count())->toBe(1)
        // New corpus content invalidates earlier matches; a no-op sync does not.
        ->and(EvidenceRevision::current($s['profile']))->toBe($revision + 1);
    expect(exportFile())->toBe($json);
});

it('reports a conflict instead of choosing a side, and only replaces when explicitly told to', function () {
    $s = approvedScenario();
    $payload = json_decode(exportFile(), true);
    $s['approvedFact']->update(['statement' => 'Reworded in the database since the export.']);

    $result = app(ApprovedEvidenceFile::class)->sync($s['profile'], $payload);

    expect($result['conflicts'])->toHaveCount(1)
        ->and($result['conflicts'][0]['database'])->toBe('Reworded in the database since the export.')
        ->and($result['conflicts'][0]['file'])->toBe('Configured webhooks for merchant retry schedules.')
        ->and($s['approvedFact']->fresh()->statement)->toBe('Reworded in the database since the export.');

    $accepted = app(ApprovedEvidenceFile::class)->sync($s['profile'], $payload, acceptFile: true);

    expect($accepted['replaced'])->toBe(1)->and($s['approvedFact']->fresh()->statement)->toBe('Configured webhooks for merchant retry schedules.');
});

it('exits non-zero from the command when it finds a conflict', function () {
    $s = approvedScenario();
    $path = sys_get_temp_dir().'/approved-evidence-'.uniqid().'.json';
    file_put_contents($path, exportFile());
    $s['approvedFact']->update(['statement' => 'Changed locally.']);

    expect(Artisan::call('career:sync-approved', ['path' => $path]))->toBe(1)
        ->and(Artisan::output())->toContain('Conflict');
    unlink($path);
});

it('refuses a file that is not an approved-evidence export', function () {
    $s = ClarificationFixtures::scenario();

    expect(fn () => app(ApprovedEvidenceFile::class)->sync($s['profile'], ['facts' => []]))->toThrow(InvalidArgumentException::class);
});

it('does not let the legacy canonical import overwrite a fact created from approved evidence', function () {
    $s = approvedScenario();
    $key = $s['approvedFact']->key;
    $statement = $s['approvedFact']->statement;

    $dataset = [
        'career_profile' => ['key' => 'p', 'name' => 'Test Person', 'email' => 'dan.sias@gmail.com'],
        'employers' => [['key' => 'rocketgate', 'name' => 'RocketGate', 'short_name' => null, 'description' => null]],
        'roles' => [['key' => 'rg-role', 'employer_key' => 'rocketgate', 'title' => 'Developer Support Engineer', 'start_year' => 2025, 'start_month' => 5, 'end_year' => null, 'end_month' => null, 'sort_order' => 0, 'summary' => null]],
        'projects' => [], 'skills' => [], 'educations' => [],
        'career_facts' => [[
            'key' => $key, 'fact_type' => 'bullet', 'statement' => 'Overwriting text from the legacy dataset.', 'verification' => 'verified', 'visibility' => 'public',
            'notes' => null, 'attributable' => ['type' => 'role', 'key' => 'rg-role'], 'evidence' => [], 'metric' => null, 'skills' => [],
        ]],
    ];
    $path = sys_get_temp_dir().'/career-import-test-'.uniqid().'.json';
    file_put_contents($path, json_encode($dataset));

    $code = Artisan::call('career:import', ['path' => $path, '--user-email' => $s['profile']->user->email]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Conflict')->toContain($key)
        ->and($s['approvedFact']->fresh()->statement)->toBe($statement)
        ->and($s['approvedFact']->evidence()->where('metadata->origin', 'clarification')->count())->toBe(1);
    unlink($path);
});
