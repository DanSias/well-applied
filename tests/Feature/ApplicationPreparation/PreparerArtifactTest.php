<?php

use App\Support\ApplicationPreparation\PreparationArtifacts;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PreparationFixtures as F;

beforeEach(function () {
    config(['services.browser_worker.token' => 'inspector-token', 'services.preparer_worker.token' => 'preparer-token']);
});

function prepClaimedArtifactRun(): array
{
    $s = F::queuedRun();
    test()->withHeaders(['Authorization' => 'Bearer preparer-token'])->postJson('/api/worker/preparer/agent-runs/claim', ['worker_identity' => 'prep-1'])->assertOk();

    return $s;
}

function prepPutShot($t, int $id, string $name, string $body, string $identity = 'prep-1')
{
    return $t->call('PUT', "/api/worker/preparer/agent-runs/{$id}/screenshots/{$name}", [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer preparer-token', 'HTTP_X_WORKER_IDENTITY' => $identity, 'CONTENT_TYPE' => 'image/png', 'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

it('keeps an uploaded screenshot on the private disk under the attempt, never a public path', function () {
    Storage::fake('public');
    ['run' => $run] = prepClaimedArtifactRun();

    prepPutShot($this, $run->id, 'before-01of02-y00000.png', F::PNG)->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);

    Storage::disk('local')->assertExists("application-preparations/{$run->id}/screenshots/before-01of02-y00000.png");
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(app(PreparationArtifacts::class)->screenshot($run, 'before-01of02-y00000.png'))->toBe(F::PNG)
        ->and(app(PreparationArtifacts::class)->screenshotNames($run))->toBe(['before-01of02-y00000.png']);
});

it('treats an identical re-upload as a duplicate and refuses a different file under the same name', function () {
    ['run' => $run] = prepClaimedArtifactRun();
    prepPutShot($this, $run->id, 'before-01of02-y00000.png', F::PNG)->assertOk();

    prepPutShot($this, $run->id, 'before-01of02-y00000.png', F::PNG)->assertOk()->assertJson(['duplicate' => true]);
    prepPutShot($this, $run->id, 'before-01of02-y00000.png', F::PNG.'different')->assertStatus(409)->assertJson(['reason' => 'artifact_conflict']);

    expect(app(PreparationArtifacts::class)->screenshot($run, 'before-01of02-y00000.png'))->toBe(F::PNG);
});

it('refuses anything that is not a screenshot name, so a worker cannot choose a path', function (string $name) {
    ['run' => $run] = prepClaimedArtifactRun();

    prepPutShot($this, $run->id, $name, F::PNG)->assertNotFound();

    expect(Storage::disk('local')->allFiles("application-preparations/{$run->id}"))->not->toContain("application-preparations/{$run->id}/screenshots/".$name);
    expect(collect(Storage::disk('local')->allFiles())->filter(fn ($f) => str_contains($f, 'passwd') || str_contains($f, '..')))->toBeEmpty();
})->with([
    'dot dot' => ['..%2F..%2Fescape-01of01-y00000.png'],
    'encoded slash' => ['a%2Fb-01of01-y00000.png'],
    'no extension' => ['before-01of01-y00000'],
    'wrong extension' => ['before-01of01-y00000.php'],
    'uppercase' => ['BEFORE-01of01-y00000.png'],
    'extra segment' => ['before-01of01-y00000.png.php'],
    'report' => ['report.json'],
    'input' => ['input.json'],
    'resume' => ['resume.pdf'],
]);

it('refuses a non-PNG, an empty body and an oversized file', function () {
    ['run' => $run] = prepClaimedArtifactRun();

    prepPutShot($this, $run->id, 'before-01of02-y00000.png', '<?php echo 1;')->assertStatus(422)->assertJson(['reason' => 'artifact_not_png']);
    prepPutShot($this, $run->id, 'before-01of02-y00000.png', '')->assertStatus(422);
    prepPutShot($this, $run->id, 'before-02of02-y00960.png', F::PNG.str_repeat('x', PreparationArtifacts::MAX_SCREENSHOT_BYTES))->assertStatus(422)->assertJson(['reason' => 'artifact_too_large']);

    expect(app(PreparationArtifacts::class)->screenshotNames($run))->toBe([]);
});

it('caps the number of screenshots per attempt', function () {
    ['run' => $run] = prepClaimedArtifactRun();
    $artifacts = app(PreparationArtifacts::class);

    for ($i = 0; $i < PreparationArtifacts::MAX_SCREENSHOTS; $i++) {
        $artifacts->putScreenshot($run, sprintf('before-%02dof99-y%05d.png', $i % 99 + 1, $i), F::PNG.$i);
    }

    prepPutShot($this, $run->id, 'after_fill-01of01-y00000.png', F::PNG)->assertStatus(422)->assertJson(['reason' => 'artifact_limit']);
});

it('accepts screenshots only from the claimant of a live run, and only into that run', function () {
    ['run' => $mine] = prepClaimedArtifactRun();
    ['run' => $other] = F::queuedRun(job: ['source_url' => 'https://boards.greenhouse.io/other/jobs/9']);

    prepPutShot($this, $mine->id, 'before-01of01-y00000.png', F::PNG, 'prep-2')->assertForbidden();
    // The other run is still queued: nobody has claimed it, so it takes nothing.
    prepPutShot($this, $other->id, 'before-01of01-y00000.png', F::PNG)->assertForbidden();
    expect(Storage::disk('local')->exists("application-preparations/{$other->id}/screenshots/before-01of01-y00000.png"))->toBeFalse();

    $mine->update(['claim_expires_at' => now()->subSecond()]);
    prepPutShot($this, $mine->id, 'before-01of01-y00000.png', F::PNG)->assertStatus(409);
    expect(app(PreparationArtifacts::class)->screenshotNames($mine))->toBe([]);
});

it('serves no artifact over HTTP: the only readers are the application\'s own code', function () {
    ['run' => $run] = prepClaimedArtifactRun();
    prepPutShot($this, $run->id, 'before-01of02-y00000.png', F::PNG)->assertOk();

    // The default private disk is not web-visible, and there is no preparer route that returns a screenshot.
    // (The private disk's own serve route only answers signed URLs, so an unsigned path is refused outright.)
    expect($this->get("/storage/application-preparations/{$run->id}/screenshots/before-01of02-y00000.png")->status())->toBeIn([403, 404]);
    $this->get("/application-preparations/{$run->id}/input.json")->assertNotFound();
    $this->withHeaders(['Authorization' => 'Bearer preparer-token', 'X-Worker-Identity' => 'prep-1'])
        ->get("/api/worker/preparer/agent-runs/{$run->id}/screenshots/before-01of02-y00000.png")->assertStatus(405);
});
