<?php

namespace App\Support\ProfileSynthesis;

use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Support\Clarification\EvidenceRevision;
use App\Support\ResumeProfile\QuestionWriter;
use Illuminate\Support\Facades\DB;

/**
 * A deliberate, infrequent step: assemble the evidence inventory, make one local-model call for the
 * positioning language, validate it, and save a new draft version. An unchanged input (same evidence,
 * sample and prompt) returns the existing version without calling the model; a rejected answer is
 * retried once with the exact problems, and a second rejection saves nothing.
 */
final class SynthesizeProfile
{
    private const MAX_CALLS = 2;

    public function __construct(
        private readonly ProfileAssembly $assembly,
        private readonly ProfileSynthesisPrompt $prompt,
        private readonly ProfilePresentationValidator $validator,
        private readonly QuestionWriter $writer,
    ) {}

    /**
     * @throws \RuntimeException when the model fails or never produces valid language
     */
    public function synthesize(ResumeProfile $profile, bool $force = false): ResumeProfileSynthesis
    {
        $career = $profile->careerProfile;
        $inventory = $this->assembly->assemble($profile);
        $hash = hash('sha256', json_encode([ProfileSynthesisPrompt::VERSION, $inventory, $this->prompt->input($inventory, $career)], JSON_THROW_ON_ERROR));

        $existing = ResumeProfileSynthesis::query()->where('resume_profile_id', $profile->id)->where('input_hash', $hash)->where('status', '!=', 'superseded')->latest('version')->first();

        if ($existing !== null && ! $force) {
            return $existing;
        }

        $schema = $this->prompt->schema($this->prompt->shownProjectIds($inventory));
        $feedback = null;
        $started = microtime(true);
        $calls = 0;
        $usage = [];
        $result = null;
        $last = [];

        while ($calls < self::MAX_CALLS) {
            $calls++;
            $response = $this->writer->write($this->prompt->system(), $this->prompt->user($inventory, $career, $feedback), $schema);
            $usage[] = $response['usage'];
            $last = $this->validator->validate($response['content'], $inventory, $career);

            if ($last['errors'] === []) {
                $result = ['content' => $last['content'], 'model' => $response['model'], 'notes' => $last['notes']];

                break;
            }

            $feedback = '- '.implode("\n- ", $last['errors']);
        }

        if ($result === null) {
            throw new \RuntimeException('The synthesized profile failed validation: '.implode(' ', $last['errors']));
        }

        return DB::transaction(function () use ($profile, $inventory, $hash, $result, $usage, $calls, $started) {
            $version = (int) ResumeProfileSynthesis::query()->where('resume_profile_id', $profile->id)->max('version') + 1;
            ResumeProfileSynthesis::query()->where('resume_profile_id', $profile->id)->where('status', 'draft')->update(['status' => 'superseded']);

            return ResumeProfileSynthesis::create([
                'career_profile_id' => $profile->career_profile_id, 'resume_profile_id' => $profile->id, 'version' => $version, 'status' => 'draft',
                'evidence_revision' => EvidenceRevision::current($profile->careerProfile), 'market_sample_id' => $inventory['provenance']['sample']['id'] ?? null,
                'pilot' => (bool) ($inventory['provenance']['sample']['pilot'] ?? false), 'input_hash' => $hash,
                'prompt_version' => ProfileSynthesisPrompt::VERSION, 'generated_by' => 'ollama:'.$result['model'],
                'usage' => ['model_calls' => $calls, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'tokens' => $usage],
                'content' => ['inventory' => $inventory, 'presentation' => $result['content']],
                'validation' => ['errors' => [], 'notes' => $result['notes']],
            ]);
        });
    }
}
