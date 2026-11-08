<?php

namespace App\Support\ReadinessReview;

use App\Contracts\GeneratesReadinessReview;
use App\Models\ResumeReadinessReview;
use App\Models\ResumeVariant;
use App\Support\Clarification\ActiveLimitations;
use App\Support\JobTailoring\JobRelevance;
use App\Support\JobTailoring\ProfileJobAdapter;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ResumeVariant\CompositionChecks;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use Throwable;

/**
 * The Application Readiness Review of one saved resume variant: read the exact artifact, check the document, ask one
 * bounded structured question of the model about what the resume says against the job, hold the answer to the resume,
 * and classify every gap from approved evidence. It writes no resume wording and changes no fact, selection or variant.
 */
final class RunReadinessReview
{
    public function __construct(
        private readonly ResumeArtifact $artifact,
        private readonly DocumentChecks $documentChecks,
        private readonly ReadinessPrompt $prompt,
        private readonly GeneratesReadinessReview $provider,
        private readonly ValidateAssessment $validator,
        private readonly ClassifyGaps $classifier,
        private readonly ReadinessFingerprints $fingerprints,
        private readonly CompositionChecks $composition,
        private readonly ResumeCandidatePayloadBuilder $candidates,
    ) {}

    /**
     * Queue a review of this variant, or return the one already queued or running.
     */
    public function request(ResumeVariant $variant): ResumeReadinessReview
    {
        $active = ResumeReadinessReview::query()->where('resume_variant_id', $variant->id)->whereIn('status', ['queued', 'running'])->latest('id')->first();

        if ($active !== null) {
            return $active;
        }

        $variant->loadMissing('jobAnalysis.jobPosting');

        return ResumeReadinessReview::create([
            'resume_variant_id' => $variant->id,
            'job_analysis_id' => $variant->job_analysis_id,
            'status' => 'queued',
            'requested_at' => now(),
            'description_fingerprint' => $this->fingerprints->description($variant->jobAnalysis->jobPosting),
            'analysis_fingerprint' => $this->fingerprints->analysis($variant->jobAnalysis),
            'prompt_version' => ReadinessPrompt::VERSION,
        ]);
    }

    public function run(ResumeReadinessReview $review): void
    {
        $started = microtime(true);
        $review->update(['status' => 'running', 'started_at' => now(), 'phase' => 'document']);

        try {
            $variant = $review->variant()->with(['careerProfile', 'jobAnalysis.jobPosting', 'synthesis', 'experienceRoles.bullets.citations.careerFact', 'summaryEvidence.careerFact'])->firstOrFail();
            $analysis = $variant->jobAnalysis;

            $t = microtime(true);
            $artifact = $this->artifact->read($variant);
            $documentFindings = $this->documentChecks->run($variant, $artifact['passages'], $artifact['pdf_text'], $artifact['pages']);
            $documentMs = (int) round((microtime(true) - $t) * 1000);

            $review->update(['phase' => 'assessment', 'artifact_fingerprint' => $artifact['document_key']]);
            $findingIds = $analysis->findings()->pluck('id')->map(fn ($i) => (int) $i)->all();
            $schema = $this->prompt->schema(array_keys($artifact['passages']), $findingIds);

            $t = microtime(true);
            $response = $this->provider->generate($this->prompt->system(), $this->prompt->user($analysis, $artifact['passages']), $schema);
            $modelMs = (int) round((microtime(true) - $t) * 1000);

            $validated = $this->validator->validate($response->structuredContent, $artifact['passages'], $findingIds, ActiveLimitations::terms($variant->careerProfile));

            $review->update(['phase' => 'classification', 'model' => $response->generatedBy()]);
            $t = microtime(true);
            $resumeKeys = $this->resumeFactKeys($variant);
            $relevance = app(JobRelevance::class)->analyze($analysis, $variant->synthesis->content['inventory']['facts'], $variant->careerProfile);
            $classification = $this->classifier->classify($variant, $validated['assessment']['requirements'], $resumeKeys, $relevance, $artifact['pdf_text']);
            $composition = $this->compositionRisks($variant, $relevance, $resumeKeys);
            $classifyMs = (int) round((microtime(true) - $t) * 1000);

            $result = $this->compose($variant, $validated, $classification, $composition, $documentFindings, $artifact);

            $review->update([
                'status' => 'succeeded', 'phase' => null, 'finished_at' => now(), 'result' => $result, 'failure_message' => null,
                'metrics' => [
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'document_ms' => $documentMs, 'model_ms' => $modelMs, 'classification_ms' => $classifyMs,
                    'model_calls' => 1, 'prompt_tokens' => $response->usage['input_tokens'] ?? null, 'completion_tokens' => $response->usage['output_tokens'] ?? null,
                    'reasoning_tokens' => $response->usage['reasoning_tokens'] ?? null, 'output_budget' => OllamaReadinessReviewClient::MAX_OUTPUT_TOKENS,
                    'reasoning_effort' => OllamaReadinessReviewClient::REASONING_EFFORT,
                ],
            ]);
        } catch (Throwable $e) {
            $review->update(['status' => 'failed', 'phase' => null, 'finished_at' => now(), 'failure_message' => mb_substr($e->getMessage(), 0, 500), 'metrics' => ['duration_ms' => (int) round((microtime(true) - $started) * 1000)]]);

            throw $e;
        }
    }

    /**
     * @return array<int, string>
     */
    private function resumeFactKeys(ResumeVariant $variant): array
    {
        $keys = $variant->summaryEvidence->map(fn ($e) => $e->careerFact->key)->all();

        foreach ($variant->experienceRoles as $role) {
            foreach ($role->bullets as $bullet) {
                foreach ($bullet->citations as $citation) {
                    $keys[] = $citation->careerFact->key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The composition checks, recomputed for exactly this resume.
     *
     * @param  array<string, mixed>  $relevance
     * @param  array<int, string>  $resumeKeys
     * @return array<int, array<string, mixed>>
     */
    private function compositionRisks(ResumeVariant $variant, array $relevance, array $resumeKeys): array
    {
        $raw = [];

        foreach ((array) ($variant->selection_raw_response['experience'] ?? []) as $r) {
            foreach ($r['bullet_groups'] as $g) {
                $raw[$r['role_id'].':'.$g['order']] = $g['job_analysis_finding_ids'] ?? [];
            }
        }

        $content = ['experience' => [], 'summary_evidence' => $variant->summaryEvidence->map(fn ($e) => $e->careerFact->key)->all()];

        foreach ($variant->experienceRoles->sortBy('display_order') as $role) {
            $content['experience'][] = ['role_id' => $role->role_id, 'bullet_groups' => $role->bullets->sortBy('display_order')->map(fn ($b) => [
                'order' => $b->display_order, 'career_fact_keys' => $b->citations->map(fn ($c) => $c->careerFact->key)->all(), 'job_analysis_finding_ids' => $raw[$role->role_id.':'.$b->display_order] ?? [],
            ])->values()->all()];
        }

        $effective = app(ProfileJobAdapter::class)->adapt(EffectiveProfile::resolve($variant->synthesis), $relevance);
        $facts = collect($this->candidates->buildForCareer($variant->careerProfile)['career_facts'])->keyBy('key')->all();

        return $this->composition->forJob($content, $facts, $variant->careerProfile, $effective, $relevance, $variant->jobAnalysis);
    }

    /**
     * @param  array{assessment: array<string, mixed>, adjustments: array<int, string>}  $validated
     * @param  array{requirements: array<string, array<string, mixed>>, terms: array<int, array<string, mixed>>}  $classification
     * @param  array<int, array<string, mixed>>  $composition
     * @param  array<int, array<string, string>>  $documentFindings
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function compose(ResumeVariant $variant, array $validated, array $classification, array $composition, array $documentFindings, array $artifact): array
    {
        $assessment = $validated['assessment'];
        $requirements = array_map(fn (array $r) => [...$r, 'gap' => $classification['requirements'][$r['id']] ?? null], $assessment['requirements']);
        $counts = array_count_values(array_column($requirements, 'coverage')) + ['strong' => 0, 'partial' => 0, 'missing' => 0];
        $rank = ['critical' => 0, 'important' => 1, 'nice' => 2];
        $facts = collect($this->candidates->buildForCareer($variant->careerProfile)['career_facts'])->keyBy('key')->all();
        $improvements = [];

        // 1. Evidence the record states that the resume leaves out, for the requirements that matter most.
        $omitted = array_values(array_filter($requirements, fn (array $r) => ($r['gap']['status'] ?? null) === 'omitted_available'));
        usort($omitted, fn (array $a, array $b) => $rank[$a['priority']] <=> $rank[$b['priority']]);

        foreach ($omitted as $r) {
            $fact = $r['gap']['candidates'][0];
            $improvements[] = [
                'weight' => $r['priority'] === 'critical' ? 0 : ($r['priority'] === 'important' ? 3 : 4), 'kind' => 'add_evidence',
                'title' => 'Bring in evidence for: '.$r['text'],
                'advice' => 'Your approved record states this and the resume leaves it out. Consider replacing a weaker bullet with this accomplishment.',
                'target' => ['type' => 'role_fact', 'role_id' => $fact['role_id'], 'fact_key' => $fact['key']], 'requirement_ids' => [$r['id']], 'facts' => [['key' => $fact['key'], 'statement' => $fact['statement']]],
            ];
        }

        // 2. The resume does not show software the candidate built.
        foreach ($composition as $finding) {
            if ($finding['check'] !== 'engineering_evidence' || $finding['available'] === []) {
                continue;
            }

            $roleId = $facts[$finding['available'][0]]['attribution']['role_id'] ?? null;
            $improvements[] = [
                'weight' => 1, 'kind' => 'engineering_evidence', 'title' => 'Show software you designed or built', 'advice' => $finding['message'],
                'target' => $roleId === null ? ['type' => 'summary'] : ['type' => 'role_fact', 'role_id' => $roleId, 'fact_key' => $finding['available'][0]], 'requirement_ids' => [],
                'facts' => array_map(fn (string $k) => ['key' => $k, 'statement' => $facts[$k]['statement'] ?? $k], array_slice($finding['available'], 0, 3)),
            ];
        }

        // 3. The model's edits to what the resume already says.
        foreach ($assessment['recommendations'] as $i => $rec) {
            $target = $rec['target'];
            $improvements[] = [
                'weight' => 2 + $i / 10, 'kind' => 'edit', 'title' => $rec['title'], 'advice' => $rec['advice'], 'requirement_ids' => $rec['requirement_ids'], 'facts' => [],
                'target' => match (true) {
                    $target === 'summary' => ['type' => 'summary'],
                    $target === 'skills' => ['type' => 'skills'],
                    str_starts_with($target, 'b') => ['type' => 'bullet', 'bullet_id' => (int) substr($target, 1)],
                    default => null,
                },
            ];
        }

        usort($improvements, fn (array $a, array $b) => $a['weight'] <=> $b['weight']);

        $gaps = array_values(array_map(fn (array $r) => [
            'requirement_id' => $r['id'], 'text' => $r['text'], 'priority' => $r['priority'], 'status' => $r['gap']['status'], 'reason' => $r['gap']['reason'], 'adjacent' => $r['gap']['adjacent'],
        ], array_filter($requirements, fn (array $r) => $r['gap']['qualification_gap'] ?? false)));
        usort($gaps, fn (array $a, array $b) => $rank[$a['priority']] <=> $rank[$b['priority']]);

        $wording = [];

        foreach ((array) ($variant->review_report['items'] ?? []) as $item) {
            if ($item['status'] !== 'passed') {
                $wording[] = ['label' => $item['label'], 'status' => $item['status'], 'messages' => array_values(array_filter([...array_column($item['findings'], 'message'), $item['action']]))];
            }
        }

        return [
            'prompt_version' => ReadinessPrompt::VERSION,
            'hiring_argument' => $assessment['hiring_argument'],
            'emphasis' => $assessment['emphasis'],
            'counts' => $counts,
            'requirements' => $requirements,
            'improvements' => array_map(fn (array $i) => array_diff_key($i, ['weight' => true]), array_slice($improvements, 0, 5)),
            'gaps' => $gaps,
            'terms' => $classification['terms'],
            'document' => ['pages' => $artifact['pages'], 'text_chars' => mb_strlen($artifact['pdf_text']), 'findings' => $documentFindings],
            'risks' => ['composition' => $composition, 'wording' => $wording, 'adjustments' => $validated['adjustments']],
            'passages' => array_map(fn (array $p) => ['label' => $p['label'], 'bullet_id' => $p['bullet_id']], $artifact['passages']),
            'artifact' => ['document_key' => $artifact['document_key'], 'pdf_sha256' => $artifact['pdf_sha256']],
        ];
    }
}
