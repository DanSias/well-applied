<?php

namespace App\Support\JobQualification;

use App\Contracts\GeneratesJobQualification;
use App\Exceptions\InvalidJobQualificationResponseException;
use App\Exceptions\JobQualificationProviderException;
use App\Models\JobPosting;
use App\Models\JobQualification;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\JobAnalysis\SegmentJobPostingDescription;
use App\Support\JobQualification\Prompts\JobQualificationPromptV1;

/**
 * Qualifies one job: the deterministic eligibility gate first, a model only when the gate allows it, then the policy check,
 * then one immutable row. An unchanged job (same content, evidence and policy) reuses its stored assessment and makes no
 * model call unless a refresh is asked for. A failure writes nothing, and nothing here ever touches the job's disposition.
 *
 * @throws JobQualificationProviderException
 * @throws InvalidJobQualificationResponseException
 */
final class GenerateJobQualification
{
    public function __construct(
        private readonly GeneratesJobQualification $provider,
        private readonly JobQualificationPromptV1 $prompt,
        private readonly JobQualificationResponseValidator $validator,
        private readonly SegmentJobPostingDescription $segmenter,
        private readonly AssessEligibility $eligibility,
        private readonly ReconcileVerdict $reconcile,
        private readonly QualificationFreshness $freshness,
    ) {}

    public function generate(JobPosting $job, bool $refresh = false): JobQualification
    {
        $inputs = $this->freshness->inputs($job);

        if (! $refresh) {
            $latest = $this->freshness->latest($job);

            if ($latest !== null && $this->freshness->isFresh($latest, $inputs['content'], $inputs['profile'])) {
                return $latest;
            }
        }

        $eligibility = $this->eligibility->assess($job, $inputs['summary']['clearance_evidence']);

        $base = [
            'job_posting_id' => $job->id,
            'career_profile_id' => $job->career_profile_id,
            'content_fingerprint' => $inputs['content'],
            'profile_fingerprint' => $inputs['profile'],
            'policy_version' => QualificationPolicy::VERSION,
            'eligibility' => $eligibility->outcome,
            'eligibility_reasons' => $eligibility->reasons,
            'generated_at' => now(),
        ];

        // Ineligible or too thin to judge: say so, and spend nothing. There is no recommendation to give.
        if (! $eligibility->outcome->allowsModel()) {
            return JobQualification::query()->create($base);
        }

        $segments = $this->segmenter->segment($job->description);
        $ids = array_keys($segments);

        $response = app(GenerationMetrics::class)->measure('model', fn () => $this->provider->generate(
            $this->prompt->systemPrompt(),
            $this->prompt->userPrompt($job, $segments, $eligibility, $inputs['summary']),
            $this->prompt->jsonSchema($ids),
        ), fn ($response) => $response->usage);

        $validated = $this->validator->validate($response->structuredContent, $ids);

        $reconciled = $this->reconcile->reconcile(
            $validated,
            $eligibility->outcome,
            array_column($inputs['summary']['skills'], 'name'),
        );

        return JobQualification::query()->create($base + [
            'prompt_version' => $this->prompt->version(),
            'schema_version' => $this->prompt->schemaVersion(),
            'generated_by' => $response->generatedBy(),
            'verdict' => $reconciled['verdict'],
            'model_verdict' => $validated['verdict'],
            'assessment' => $this->storable($validated, $reconciled['technologies'], $segments),
            'adjustments' => $reconciled['adjustments'],
        ]);
    }

    /**
     * The validated response with each evidence id resolved to the posting's exact sentence, so what is stored (and shown)
     * is the source text, never something the model wrote.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<array<string, mixed>>  $technologies
     * @param  array<string, string>  $segments
     * @return array<string, mixed>
     */
    private function storable(array $validated, array $technologies, array $segments): array
    {
        $resolve = fn (array $refs): array => array_map(fn (string $id) => ['ref' => $id, 'excerpt' => $segments[$id]], array_values(array_unique($refs)));

        return [
            'role_type' => $validated['role_type'],
            'primary_role_focus' => $validated['primary_role_focus'],
            'hands_on' => $validated['hands_on'],
            'core_responsibilities' => $validated['core_responsibilities'],
            'technologies' => array_map(fn (array $t) => [
                'name' => $t['name'], 'requirement' => $t['requirement'], 'familiarity' => $t['familiarity'], 'depth' => $t['depth'],
                'transferable_accepted' => $t['transferable_accepted'], 'evidence' => $resolve($t['evidence_refs']),
            ], $technologies),
            'seniority' => $validated['seniority'],
            'fit_reasons' => array_map(fn (array $r) => ['text' => $r['text'], 'evidence' => $resolve($r['evidence_refs'])], $validated['fit_reasons']),
            'biggest_concern' => $validated['biggest_concern'] === null ? null : ['text' => $validated['biggest_concern'], 'evidence' => $resolve($validated['biggest_concern_evidence_refs'])],
            'practical_concerns' => $validated['practical_concerns'],
        ];
    }
}
