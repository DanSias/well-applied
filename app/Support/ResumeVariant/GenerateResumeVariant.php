<?php

namespace App\Support\ResumeVariant;

use App\Contracts\GeneratesResumeSelection;
use App\Contracts\GeneratesResumeWording;
use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Exceptions\ResumeEmptyOutputException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\ResumeProfileSynthesis;
use App\Models\ResumeSelectionCheckpoint;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Models\Skill;
use App\Support\Clarification\ActivePresentationPreferences;
use App\Support\GenerationAttempt\GenerationMetrics;
use App\Support\JobMatch\JobPayloadBuilder;
use App\Support\JobTailoring\JobRelevance;
use App\Support\JobTailoring\ProfileJobAdapter;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ProfileSynthesis\FirstPerson;
use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ProfileSynthesis\StoryRole;
use App\Support\ResumeDocument\ResumeText;
use App\Support\ResumeRevision\JobResumes;
use App\Support\ResumeVariant\Prompts\ProfileJobWordingPromptV4;
use App\Support\ResumeVariant\Prompts\ReferenceWordingPromptV3;
use App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV6;
use App\Support\ResumeVariant\Prompts\ResumeWordingPromptV3;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The whole resume-generation pipeline for one JobMatch, start to
 * finish:
 *
 *   deterministic payloads -> Selection provider -> validate ->
 *   trusted selection draft -> Wording provider (bounded to the
 *   approved selection) -> validate -> trusted wording draft -> one
 *   atomic transaction creating ResumeVariant + its full relational
 *   tree.
 *
 * Everything before the transaction can fail loudly without touching
 * the database at all. Mirrors App\Support\JobMatch\GenerateJobMatch's
 * structure exactly, extended to two provider calls instead of one.
 * v1 implements full generation only — regenerateWording() (reusing an
 * existing ResumeVariant's approved selection to reword without
 * re-selecting) is deliberately deferred until this base path is
 * proven.
 *
 * As of ResumeSelectionPromptV3, Selection no longer declares which
 * Project (if any) an Experience bullet group belongs to — this class
 * derives that deterministically instead, from each bullet's own
 * approved career_fact_keys (see deriveBulletGroupProjectId()), since
 * canonical CareerFact-to-Project attribution already determines it
 * with certainty. The LLM selects evidence; canonical application data
 * determines evidence provenance. See docs/resume-variant-generation.md
 * "Design boundary: selection vs. provenance".
 */
final class GenerateResumeVariant
{
    /** Total provider calls allowed for Wording, shared by validation rejections and empty outputs. */
    private const MAX_WORDING_CALLS = 3;

    /** A valid reference wording that drops approved content gets one corrective retry (calls 1 and 2), no more. */
    private const MAX_SOFT_RETRY_CALLS = 2;

    /** Selection is retried only for an empty output (one retry); its validation failures stay fatal. */
    private const MAX_SELECTION_CALLS = 2;

    public function __construct(
        private readonly GeneratesResumeSelection $selectionProvider,
        private readonly GeneratesResumeWording $wordingProvider,
        private readonly ResumeSelectionPromptV6 $selectionPrompt,
        private readonly ResumeWordingPromptV3 $wordingPrompt,
        private readonly ResumeCandidatePayloadBuilder $candidateBuilder,
        private readonly JobPayloadBuilder $jobPayloadBuilder,
        private readonly TargetTerminologyBuilder $targetTerminologyBuilder,
        private readonly ResumeSelectionResponseValidator $selectionValidator,
        private readonly ResumeWordingResponseValidator $wordingValidator,
    ) {}

    /**
     * @param  (\Closure(string): void)|null  $onPhase  Notified as the run enters 'selection', 'wording', and 'saving', so the caller can surface live progress.
     * @param  bool  $reuseCheckpoint  Reuse an unconsumed, still-matching validated Selection checkpoint instead of calling the provider. False for an explicit regenerate.
     */
    public function generateFull(JobMatch $jobMatch, ?\Closure $onPhase = null, bool $reuseCheckpoint = true): ResumeVariant
    {
        return $this->generate($jobMatch->careerProfile, $jobMatch, null, $onPhase, $reuseCheckpoint);
    }

    /**
     * A reference resume: no job, no model-chosen Selection. The approved profile decides the evidence
     * (ReferenceSelection), the same Selection validator judges it, and everything after that (Wording,
     * triage and recovery, the restricted-evidence and claim-scope guards, the review report, persistence)
     * is the job pipeline unchanged. Its wording prompt names the reference context explicitly instead of
     * pretending a job description exists.
     *
     * @param  (\Closure(string): void)|null  $onPhase
     */
    public function generateReference(ResumeProfileSynthesis $synthesis, ?\Closure $onPhase = null): ResumeVariant
    {
        return $this->generate($synthesis->careerProfile, null, $synthesis, $onPhase, false);
    }

    /**
     * A resume for one job composed from an approved profile, with no candidate match: the profile is the starting
     * candidate set and ranking prior, the job analysis (already stored) says what matters, and relevant approved
     * evidence outside the profile's emphasis is ranked in by the same selection. Selection is deterministic; one
     * wording call (with the usual validation and retries) writes the text. See App\Support\JobTailoring.
     *
     * @param  (\Closure(string): void)|null  $onPhase
     */
    public function generateForJob(JobAnalysis $analysis, ResumeProfileSynthesis $synthesis, ?\Closure $onPhase = null): ResumeVariant
    {
        return $this->generate($synthesis->careerProfile, null, $synthesis, $onPhase, false, $analysis);
    }

    private function generate(CareerProfile $profile, ?JobMatch $jobMatch, ?ResumeProfileSynthesis $synthesis, ?\Closure $onPhase, bool $reuseCheckpoint, ?JobAnalysis $analysis = null): ResumeVariant
    {
        $metrics = app(GenerationMetrics::class);
        $onPhase ??= static fn (string $phase) => null;
        $jobMode = $synthesis !== null && $analysis !== null;
        $wordingPrompt = match (true) {
            $jobMode => app(ProfileJobWordingPromptV4::class),
            $synthesis !== null => app(ReferenceWordingPromptV3::class),
            default => $this->wordingPrompt,
        };
        $effective = $synthesis === null ? null : EffectiveProfile::resolve($synthesis);
        $relevance = null;
        $baselineEffective = $effective;

        if ($jobMode) {
            // The profile is the prior; the job re-ranks it (never past the user's exclusions and pins).
            $relevance = app(JobRelevance::class)->analyze($analysis, $synthesis->content['inventory']['facts'], $profile);
            $effective = app(ProfileJobAdapter::class)->adapt($effective, $relevance);
        }

        // ---- Stage 0: deterministic payload construction ----
        if ($jobMatch !== null) {
            $candidatePayload = $this->candidateBuilder->build($jobMatch);
            $jobPayload = $this->jobPayloadBuilder->build($jobMatch->jobAnalysis);
            $targetTerminology = $this->targetTerminologyBuilder->build($jobMatch);
        } else {
            // No job: no findings and no target terms. The facts the approved profile placed are offered
            // first, so a claim shared by several places is presented where the profile ranks it highest.
            $candidatePayload = $this->candidateBuilder->buildForCareer($profile, [], array_column($effective['accomplishments'] ?? [], 'fact_key'));
            $jobPayload = $jobMode ? $this->jobPayloadBuilder->build($analysis) : ['reference' => true, 'findings' => []];
            $targetTerminology = [];
        }

        $validFactKeys = array_column($candidatePayload['career_facts'], 'key');
        $validSkillIds = array_column($candidatePayload['eligible_skills'], 'id');
        $validFindingIds = array_column($jobPayload['findings'], 'id');
        $validTargetTerms = array_column($targetTerminology, 'term');
        $directEvidenceExistsByTerm = array_combine($validTargetTerms, array_column($targetTerminology, 'direct_evidence_exists'));

        // Every role with at least one resume-eligible CareerFact
        // attributed to it directly or via a project — the set Selection
        // must fully represent (assertRoleCompleteness()); relevance
        // controls emphasis, never basic employment-history presence.
        $resumeEligibleRoleIds = collect($candidatePayload['career_facts'])
            ->pluck('attribution.role_id')
            ->filter(fn (mixed $roleId) => $roleId !== null)
            ->unique()
            ->values()
            ->all();

        // career_fact_key => the project_id it's actually attributed to
        // (independent or professional) — reused both to compute which
        // independent Projects are legally selectable and, in the
        // Selection validator, to confirm a selected_projects entry
        // only cites facts truly attributed to that exact Project. See
        // ResumeSelectionResponseValidator::assertSelectedProjects().
        $projectIdByFactKey = collect($candidatePayload['career_facts'])
            ->filter(fn (array $fact) => $fact['attribution']['project_id'] !== null)
            ->mapWithKeys(fn (array $fact) => [$fact['key'] => $fact['attribution']['project_id']])
            ->all();

        // Independent (role_id-null) Projects for this profile, with
        // real Public/Restricted-eligible evidence (the same visibility
        // backstop ResumeEligibility already applies at the CareerFact
        // level) — never a professional project's id. Selection may
        // choose at most MAX_SELECTED_PROJECTS of these (see
        // ResumeSelectionResponseValidator); a professional Project can
        // never be converted into a Selected Projects entry, since its
        // id is structurally absent from this set. See
        // docs/domain-model.md "ResumeVariant" -> "Selected Projects".
        // Sprint 1: Project Highlights may be independent OR professional
        // (role-owned) Projects. Eligibility is unchanged: only Projects
        // that carry real Public/Restricted-eligible evidence and are not
        // Private by default. The no-duplicate rule between highlights and
        // Experience is enforced by ResumeSelectionResponseValidator.
        $eligibleProjectIdsFromFacts = array_unique(array_values($projectIdByFactKey));
        $validIndependentProjectIds = Project::query()
            ->where(fn ($query) => $query->whereNull('default_visibility')->orWhere('default_visibility', '!=', 'private'))
            ->whereIn('id', $eligibleProjectIdsFromFacts)
            ->pluck('id')
            ->all();

        $roles = Role::query()
            ->whereHas('employer', fn ($query) => $query->where('career_profile_id', $profile->id))
            ->with('projects')
            ->get();

        $validRoleIds = $roles->pluck('id')->all();

        // role_id => the set of CareerFact keys eligible as Experience-
        // bullet evidence for that role — Role-direct facts, facts
        // attributed to one of that role's own Projects, and facts
        // attributed to the Employer that owns that role. Deliberately
        // computed from fresh, raw attribution data (attributable_type/
        // attributable_id), independent of $candidatePayload — this is
        // internal eligibility bookkeeping only, never serialized into
        // the provider-facing payload. CareerProfile-attributed facts
        // are never included here: see
        // ResumeSelectionResponseValidator::assertBulletGroupCareerFactsAreEligibleForRole()
        // and docs/resume-variant-generation.md "Design boundary:
        // selection vs. provenance".
        $eligibleFactKeysForRole = $this->eligibleFactKeysForRole($roles, $profile);

        $titleChoicesByRole = $roles->mapWithKeys(
            fn (Role $role) => [(string) $role->id => $this->titleChoices($role->title)]
        )->all();

        $validTitleChoiceKeys = $this->titleChoiceKeyEnum($titleChoicesByRole);

        // ---- Stage 1: Selection ----
        $selectionPromptVersion = match (true) {
            $jobMode => ReferenceSelection::VERSION.'+job-relevance-v1',
            $synthesis !== null => ReferenceSelection::VERSION,
            default => $this->selectionPrompt->version(),
        };
        $presentationPreferences = ActivePresentationPreferences::forProfile($profile);
        $selectionResponse = null;
        $validatedSelection = null;
        $tailoring = null;

        if ($synthesis !== null) {
            $onPhase('selection');
            $reference = app(ReferenceSelection::class)->build($effective, $candidatePayload);

            if ($jobMode) {
                // Which requirement each bullet answers: provenance for the review, never a claim.
                foreach ($reference['content']['experience'] as &$role) {
                    foreach ($role['bullet_groups'] as &$group) {
                        $ids = [];

                        foreach ($group['career_fact_keys'] as $key) {
                            $ids = [...$ids, ...($relevance['facts'][$key]['finding_ids'] ?? [])];
                        }

                        $group['job_analysis_finding_ids'] = array_slice(array_values(array_intersect(array_unique($ids), $validFindingIds)), 0, 3);
                    }
                    unset($group);
                }
                unset($role);

                $reference['content']['summary_evidence'] = $this->jobSummaryEvidence($reference['content'], $effective, $relevance, collect($candidatePayload['career_facts'])->keyBy('key')->all());
                $reference['composition'] = app(CompositionChecks::class)->forJob(
                    $reference['content'], collect($candidatePayload['career_facts'])->keyBy('key')->all(), $profile, $effective, $relevance, $analysis,
                );
                $baseline = app(ReferenceSelection::class)->build($baselineEffective, $candidatePayload);
                $usedKeys = fn (array $content): array => array_values(array_unique(array_merge($content['summary_evidence'], ...array_merge(...array_map(fn (array $r) => array_column($r['bullet_groups'], 'career_fact_keys'), $content['experience'])))));
                $chosen = $usedKeys($reference['content']);
                $reference['tailoring'] = [
                    'added_outside_reference' => array_values(array_diff($chosen, $usedKeys($baseline['content']))),
                    'dropped_from_reference' => array_values(array_diff($usedKeys($baseline['content']), $chosen)),
                ];
            }
            $validatedSelection = $this->selectionValidator->validate(
                $reference['content'],
                $validRoleIds,
                $validFactKeys,
                $validSkillIds,
                $eligibleFactKeysForRole,
                $validFindingIds,
                $titleChoicesByRole,
                $directEvidenceExistsByTerm,
                $resumeEligibleRoleIds,
                $validIndependentProjectIds,
                $projectIdByFactKey,
            );
            $selectionResponse = new ResumeSelectionProviderResponse('deterministic', "profile-v{$synthesis->version}", $validatedSelection);
            $tailoring = $jobMode ? [
                'mode' => 'profile_job', 'job_posting_id' => $analysis->job_posting_id, 'job_analysis_id' => $analysis->id, 'analysis_prompt_version' => $analysis->prompt_version,
                'profile_version' => $synthesis->version,
                'skills_layout' => $effective['skills_layout'] ?? null,
                'findings' => array_values(array_map(fn (array $f) => ['id' => $f['id'], 'label' => $f['label'], 'category' => $f['category'], 'weight' => round($f['weight'], 2)], $relevance['findings'])),
                'gaps' => $relevance['gaps'], 'composition' => $reference['composition'], ...$reference['tailoring'],
            ] : null;
            $metrics->event(['stage' => 'selection', 'kind' => 'reference_selection', 'groups' => array_sum(array_map(fn (array $r) => count($r['bullet_groups']), $validatedSelection['experience'])), 'trace' => $reference['trace']]);
        } else {
            if ($jobMatch === null) {
                throw new \LogicException('A job-linked run needs its JobMatch.');
            }

            $selectionSchema = $this->selectionPrompt->jsonSchema(
                $validRoleIds,
                $validFactKeys,
                $validSkillIds,
                $validFindingIds,
                $validTargetTerms,
                $validTitleChoiceKeys,
                $validIndependentProjectIds,
            );

            $selectionSystemPrompt = $this->selectionPrompt->systemPrompt();
            $selectionUserPrompt = $this->selectionPrompt->userPrompt($candidatePayload, $jobPayload, $targetTerminology, $presentationPreferences);

            // Everything that determined Selection's answer: the prompt
            // version and text, the schema, and every input the model saw.
            // Any change (profile, match, visibility, prompt) changes this
            // and makes an earlier checkpoint unusable.
            $selectionFingerprint = hash('sha256', json_encode([
                $this->selectionPrompt->version(),
                $this->selectionPrompt->schemaVersion(),
                $selectionSystemPrompt,
                $selectionSchema,
                $selectionUserPrompt,
            ], JSON_THROW_ON_ERROR));

            $validateSelection = fn (array $content): array => $this->selectionValidator->validate(
                $content,
                $validRoleIds,
                $validFactKeys,
                $validSkillIds,
                $eligibleFactKeysForRole,
                $validFindingIds,
                $titleChoicesByRole,
                $directEvidenceExistsByTerm,
                $resumeEligibleRoleIds,
                $validIndependentProjectIds,
                $projectIdByFactKey,
            );

            $checkpoint = $reuseCheckpoint
                ? ResumeSelectionCheckpoint::query()
                    ->where('job_match_id', $jobMatch->id)
                    ->where('fingerprint', $selectionFingerprint)
                    ->whereNull('consumed_at')
                    ->latest('id')
                    ->first()
                : null;

            if ($checkpoint !== null) {
                // The stored response is revalidated against the current
                // rules, so reuse never bypasses grounding or visibility.
                try {
                    $validatedSelection = $validateSelection($checkpoint->structured_content);
                    $selectionResponse = new ResumeSelectionProviderResponse($checkpoint->provider, $checkpoint->model, $checkpoint->structured_content);
                    $metrics->markSelectionReused();
                    $metrics->event(['stage' => 'selection', 'kind' => 'checkpoint_reused', 'checkpoint_id' => $checkpoint->id]);
                } catch (InvalidResumeVariantResponseException $e) {
                    $metrics->event(['stage' => 'selection', 'kind' => 'checkpoint_rejected', 'checkpoint_id' => $checkpoint->id, 'reason' => mb_substr($e->getMessage(), 0, 500)]);
                }
            }

            if ($validatedSelection === null) {
                $onPhase('selection');

                $selectionResponse = $this->selectWithEmptyOutputRetry(
                    $metrics,
                    self::MAX_SELECTION_CALLS,
                    fn () => $this->selectionProvider->generate($selectionSystemPrompt, $selectionUserPrompt, $selectionSchema),
                );

                $validatedSelection = $validateSelection($selectionResponse->structuredContent);

                ResumeSelectionCheckpoint::create([
                    'job_match_id' => $jobMatch->id,
                    'fingerprint' => $selectionFingerprint,
                    'prompt_version' => $this->selectionPrompt->version(),
                    'schema_version' => $this->selectionPrompt->schemaVersion(),
                    'provider' => $selectionResponse->provider,
                    'model' => $selectionResponse->model,
                    'structured_content' => $selectionResponse->structuredContent,
                    'duration_ms' => $metrics->stageMs('selection'),
                ]);
            }

        }

        // The Technical Skills section must not be empty. If Selection
        // returned no Skills, derive them deterministically from the
        // Skills attached to the non-Restricted facts it already chose
        // (Experience, Project Highlights, Summary), most-cited first.
        // Nothing is invented: every Skill is canonical and backed by
        // selected evidence.
        if (($validatedSelection['skills'] ?? []) === []) {
            $validatedSelection['skills'] = $this->deriveSkillsFromSelectedEvidence(
                $validatedSelection, collect($candidatePayload['career_facts'])->keyBy('key'),
            );
        }

        $selectionDraft = $this->toSelectionDraft($validatedSelection, $titleChoicesByRole, $projectIdByFactKey);

        // ---- Build Stage 2 input from exactly what Selection approved ----
        $factsByKey = collect($candidatePayload['career_facts'])->keyBy('key');
        // Restricted-evidence, skill-provenance and guardrail inputs: the same ones an edited resume is judged by.
        $inputs = app(WordingContextInputs::class)->build($profile, $factsByKey->all(), $candidatePayload['eligible_skills']);
        $guardrailByFactKey = $inputs['guardrailByFactKey'];
        $restrictedFactKeys = $inputs['restrictedFactKeys'];
        $factTextByKey = $inputs['factTextByKey'];
        $skillIdsByFactKey = $inputs['skillIdsByFactKey'];
        $canonicalSkillsById = $inputs['canonicalSkillsById'];
        $textRecognizedSkillIdsByFactKey = $inputs['textRecognizedSkillIdsByFactKey'];

        // The target_term_usages -> Resume Wording handoff, designed as
        // location-scoped guidance rather than a global list or a
        // second evidence channel — see
        // buildDirectTargetTermsByLocation()'s own docblock and
        // docs/resume-variant-generation.md "Target-term location
        // integrity". Deliberately built from $selectionDraft->targetTermUsages
        // (already validated by ResumeSelectionResponseValidator,
        // including the new location/evidence-locality check) rather
        // than re-deriving anything — no new query, no re-validation.
        $directTargetTermsByLocation = $this->buildDirectTargetTermsByLocation($selectionDraft);

        // The Summary's own closed-world Skill allow-list, surfaced to
        // Wording as a generation-time affordance after a deterministic
        // investigation found qwen3.8:27b stochastically borrowing
        // canonical Skills from Experience/Selected-Project evidence
        // into the summary despite an existing prose rule against it —
        // see docs/resume-variant-generation.md "Skill provenance".
        // Reuses ResumeWordingResponseValidator::authorizedSkillIds()
        // directly (now public) rather than a second definition of
        // Skill authorization: this is exactly the same set the
        // validator itself will check the generated summary against.
        // Deliberately scoped to the Summary only — no Experience/
        // Selected-Project equivalent, since only the Summary has ever
        // been observed to leak.
        $summaryAuthorizedSkillNames = collect($this->wordingValidator->authorizedSkillIds(
            $selectionDraft->summaryEvidenceFactKeys, $skillIdsByFactKey, $textRecognizedSkillIdsByFactKey,
        ))->map(fn (int $id) => $canonicalSkillsById[$id])->all();

        $wordingInput = $this->buildWordingInput($selectionDraft, $factsByKey, $directTargetTermsByLocation, $summaryAuthorizedSkillNames);

        if ($effective !== null) {
            $wordingInput['reference_context'] = ReferenceSelection::wordingContext($effective);

            if ($jobMode) {
                $wordingInput['reference_context']['kind'] = 'job_tailored_resume';
                $wordingInput['job_context'] = $this->jobContext($analysis, $relevance, $factsByKey->all());
            }

            // A first-person fact authorizes what the summary may claim; the model is told its wording is not to be used.
            $wordingInput['summary_evidence'] = array_map(
                fn (array $fact) => FirstPerson::in((string) ($fact['statement'] ?? '')) ? [...$fact, 'support_only' => true] : $fact,
                $wordingInput['summary_evidence'],
            );
        }
        if ($jobMode) {
            // How the bullets work together: why each matters, what its neighbours establish, what must not be said twice.
            $plan = app(EditorialPlan::class)->build($selectionDraft->experience, $factsByKey, $relevance);
            $wordingInput['editorial_plan'] = ['career_narrative' => $plan['career_narrative'], 'ownership_in' => $plan['ownership_in']];

            foreach ($wordingInput['experience'] as $r => $role) {
                foreach ($role['bullet_groups'] as $g => $group) {
                    $wordingInput['experience'][$r]['bullet_groups'][$g]['editorial'] = $plan['groups']["{$role['role_id']}:{$group['bullet_group_index']}"] ?? null;
                }
            }
        }

        // What the model reads is leaner than what the validators hold: the fields it cannot use are left out and a fact
        // the summary shares with a bullet is sent once. Summary correction, later, still needs the summary's full facts.
        $summaryFacts = $jobMode ? array_values(array_filter((array) $wordingInput['summary_evidence'])) : [];

        if ($jobMode) {
            $wordingInput = SlimWordingFacts::input($wordingInput);
        }

        $careerFactKeysByLocation = $this->buildCareerFactKeysByLocation($selectionDraft);
        $denylistTerms = $this->buildDenylistTerms($validTargetTerms, $selectionDraft);

        $rolesNeedingWording = collect($selectionDraft->experience)->filter(fn (RoleSelectionDraft $role) => $role->bulletGroups !== []);
        $rolesInSelection = $rolesNeedingWording->pluck('roleId')->all();
        $expectedBulletGroupIndexesByRole = $rolesNeedingWording->mapWithKeys(
            fn (RoleSelectionDraft $role) => [$role->roleId => array_keys($role->bulletGroups)]
        )->all();
        $selectedProjectIds = collect($selectionDraft->selectedProjects)->pluck('projectId')->all();

        // ---- Stage 2: Wording ----
        $wordingSchema = $wordingPrompt->jsonSchema($rolesInSelection, $selectedProjectIds);

        // Wording's validators are strict (grounding, guardrails,
        // Restricted evidence), and a rejection after minutes of work
        // gets informed retries with the exact rejection reason. Transport
        // failures are not retried here (the transport already retries).
        $baseWordingPrompt = $wordingPrompt->userPrompt($wordingInput, $denylistTerms);
        $wordingContext = new WordingCheckContext(
            validRoleIds: $rolesInSelection,
            expectedBulletGroupIndexesByRole: $expectedBulletGroupIndexesByRole,
            validSelectedProjectIds: $selectedProjectIds,
            denylistTerms: $denylistTerms,
            careerFactKeysByLocation: $careerFactKeysByLocation,
            guardrailByFactKey: $guardrailByFactKey,
            skillIdsByFactKey: $skillIdsByFactKey,
            canonicalSkillsById: $canonicalSkillsById,
            textRecognizedSkillIdsByFactKey: $textRecognizedSkillIdsByFactKey,
            directTargetTermsByLocation: $directTargetTermsByLocation,
            restrictedFactKeys: $restrictedFactKeys,
            factTextByKey: $factTextByKey,
            restrictedTerms: $inputs['restrictedTerms'],
            genericTerms: $inputs['genericTerms'],
            limitationTerms: $inputs['limitationTerms'],
            declaredScopesByKey: $inputs['declaredScopesByKey'],
            // A reference resume is judged on its material claims, and its summary must keep what the approved profile's does.
            claimFidelity: $synthesis !== null,
            retention: $effective === null || $jobMode ? [] : app(ReferenceSelection::class)->retention($effective, array_map(fn (string $key) => (string) ($factsByKey->get($key)['statement'] ?? ''), $selectionDraft->summaryEvidenceFactKeys)),
        );
        $reviewItems = [];
        $rejection = null;

        $onPhase('wording');

        // One shared budget of provider calls covers validation
        // rejections (retried with the exact reason as feedback) and
        // empty model outputs (retried unchanged: there is nothing to
        // correct). Every rejection and empty output is logged and
        // recorded on the attempt.
        for ($wordingCall = 1; ; $wordingCall++) {
            $wordingUserPrompt = $rejection === null ? $baseWordingPrompt : $baseWordingPrompt
                ."\n\n--- Your previous response was rejected ---\n".$rejection
                ."\nWrite the response again, fixing exactly the problems listed above and changing nothing else.\n"
                .'--- End of rejection notice ---';

            try {
                $wordingResponse = $metrics->measure('wording', fn () => $this->wordingProvider->generate(
                    $wordingPrompt->systemPrompt(),
                    $wordingUserPrompt,
                    $wordingSchema,
                ), fn ($response) => $response->usage);
            } catch (ResumeEmptyOutputException $e) {
                $this->recordEmptyOutput($metrics, 'wording', $wordingCall, $e);

                if ($wordingCall >= self::MAX_WORDING_CALLS) {
                    throw $this->exhaustedEmptyOutput('Wording', $wordingCall, $e);
                }

                continue;
            }

            // Em dashes are normalized deterministically before judging
            // (ResumeText::clean()). Judging never throws on content: a
            // location that must not ship is generalized or omitted by
            // RecoverWording while every other location is kept. Only a
            // structural problem (missing or duplicate bullets) needs a
            // new model response.
            $triage = $this->wordingValidator->triage($this->cleanStrings($wordingResponse->structuredContent), $wordingContext);

            // A valid response that dropped something the approved profile states, or never says a fact it cites, earns
            // one corrective retry; if it persists, it ships with the omission flagged in the review report.
            // A bullet that only leaves out one of its own facts is rewritten on its own below; asking for the whole resume
            // again costs a full reasoning pass to fix one sentence.
            $bulletOnly = $jobMode && $triage->bulletProblems !== []
                && count($triage->bulletProblems) === count($triage->softProblems)
                && count($triage->bulletProblems) <= (int) config('resume.composition.retention.max_bullet_corrections', 2);

            if ($triage->isStructurallyValid() && $triage->softProblems !== [] && ! $bulletOnly && $wordingCall < self::MAX_SOFT_RETRY_CALLS) {
                $reason = mb_substr('The response is valid but should keep the following: '.implode(' ', $triage->softProblems), 0, 1500);
                $metrics->event(['stage' => 'wording', 'call' => $wordingCall, 'kind' => 'retention_retry', 'reason' => $reason]);
                $rejection = $reason;

                continue;
            }

            if ($triage->isStructurallyValid()) {
                $recovered = app(RecoverWording::class)->recover($triage, $wordingContext);
                $stillOpen = $triage->softProblems;

                if ($jobMode) {
                    // Recovery only removes. A summary it cut down gets one narrow correction (never a retry of the resume),
                    // and one that stays collapsed ships flagged, never as a finished summary.
                    $recovered = app(CorrectSummary::class)->apply($recovered, $this->wordingProvider, app(ProfileJobWordingPromptV4::class), [...$wordingInput, 'summary_evidence' => array_map(fn (array $f) => SlimWordingFacts::fact($f, withRole: true), $summaryFacts)], $wordingContext, $metrics);
                    $recovered = app(CorrectBullets::class)->apply($recovered, $this->wordingProvider, app(ProfileJobWordingPromptV4::class), $wordingInput, $wordingContext, $metrics);
                    $stillOpen = array_values(array_filter($triage->softProblems, fn (string $m) => ! array_filter($recovered['resolved'], fn (string $key) => str_contains($m, "[{$key}]"))));
                    $recovered = $this->withoutDuplicates($recovered, $selectionDraft, $factsByKey, $tailoring, $relevance);
                }

                $validatedWording = $recovered['content'];
                $reviewItems = $recovered['items'];

                $open = $jobMode ? $stillOpen : $triage->softProblems;

                if ($open !== []) {
                    $reviewItems[] = ['location' => 'profile', 'status' => 'review', 'findings' => array_map(fn (string $m) => ['kind' => 'retention', 'severity' => 'review', 'detail' => '', 'message' => $m], $open), 'action' => null, 'text' => null, 'fact_keys' => []];
                }

                break;
            }

            $reason = mb_substr('Resume Wording provider response failed validation: '.implode(' ', $triage->structuralErrors), 0, 1000);
            $metrics->event(['stage' => 'wording', 'call' => $wordingCall, 'kind' => 'validation_rejected', 'reason' => $reason]);
            Log::warning('Resume Wording response rejected by validation.', [
                'job_match_id' => $jobMatch?->id,
                'call' => $wordingCall,
                'reason' => $reason,
            ]);

            if ($wordingCall >= self::MAX_WORDING_CALLS) {
                throw new InvalidResumeVariantResponseException($reason, context: $wordingResponse->structuredContent);
            }

            $rejection = $reason;
        }

        $wordingDraft = $this->toWordingDraft($validatedWording);
        $reviewReport = $this->buildReviewReport($reviewItems, collect($candidatePayload['career_facts'])->keyBy('key'), $roles);

        // ---- Persist ----
        $onPhase('saving');

        return DB::transaction(function () use (
            $profile, $jobMatch, $synthesis, $analysis, $tailoring, $wordingPrompt, $selectionPromptVersion, $selectionDraft, $wordingDraft,
            $candidatePayload, $jobPayload, $targetTerminology, $presentationPreferences,
            $selectionResponse, $wordingResponse,
            $validatedSelection, $validatedWording, $wordingInput,
            $factsByKey, $reviewReport,
        ) {
            $factIdsByKey = CareerFact::query()
                ->where('career_profile_id', $profile->id)
                ->pluck('id', 'key')
                ->all();

            $rolesById = Role::query()
                ->whereIn('id', collect($selectionDraft->experience)->pluck('roleId'))
                ->with('employer')
                ->get()
                ->keyBy('id');

            $skillsById = Skill::query()
                ->whereIn('id', collect($selectionDraft->skills)->pluck('skillId'))
                ->get()
                ->keyBy('id');

            $selectedProjectsById = Project::query()
                ->whereIn('id', collect($selectionDraft->selectedProjects)->pluck('projectId'))
                ->get()
                ->keyBy('id');

            // Education is deterministically included in full, never a
            // Selection decision — every canonical record for this
            // profile, most-recently-completed first with canonical
            // sort_order as tiebreak. See ResumeSelectionDraft's docblock.
            $educationRecords = Education::query()
                ->where('career_profile_id', $profile->id)
                ->orderByDesc('end_year')
                ->orderBy('sort_order')
                ->get();

            $summaryQualified = collect($selectionDraft->targetTermUsages)->first(
                fn (TargetTermUsageDraft $usage) => $usage->location === ResumeTermUsageLocation::Summary
                    && $usage->posture === ResumeClaimPosture::Qualified
            );

            $finalSummary = $summaryQualified === null || $wordingDraft->summary === ''
                ? $wordingDraft->summary
                : $this->appendQualifiedClause($wordingDraft->summary, $summaryQualified->relationshipPhraseKey, $summaryQualified->term);

            $variant = ResumeVariant::create([
                'career_profile_id' => $profile->id,
                'job_match_id' => $jobMatch?->id,
                'job_analysis_id' => $analysis?->id,
                'resume_profile_synthesis_id' => $synthesis?->id,
                'schema_version' => $this->selectionPrompt->schemaVersion(),
                'selection_prompt_version' => $selectionPromptVersion,
                'wording_prompt_version' => $wordingPrompt->version(),
                'selection_generated_by' => $selectionResponse->generatedBy(),
                'wording_generated_by' => $wordingResponse->generatedBy(),
                'generated_at' => now(),
                'selection_input_snapshot' => [
                    'candidate' => $candidatePayload,
                    'job' => $jobPayload,
                    'target_terminology' => $targetTerminology,
                    'presentation_preferences' => $presentationPreferences,
                    ...($tailoring === null ? [] : ['tailoring' => $tailoring]),
                ],
                'selection_raw_response' => $validatedSelection,
                'wording_input_snapshot' => $wordingInput,
                'wording_raw_response' => $validatedWording,
                'summary' => $finalSummary,
                'review_report' => $reviewReport,
            ]);

            if ($jobMatch !== null) {
                ResumeSelectionCheckpoint::query()
                    ->where('job_match_id', $jobMatch->id)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);
            }

            $bulletIdsByLocation = [];

            // Chronology is always deterministic, never model-decided
            // (docs/domain-model.md "ResumeVariant" -> "Titles,
            // chronology, and attribution") — the role snapshot rows'
            // own display_order is computed here, once, the same way
            // ResumeVariantController historically re-derived it on
            // every render.
            $orderedExperience = collect($selectionDraft->experience)
                ->sortByDesc(function (RoleSelectionDraft $role) use ($rolesById) {
                    $roleModel = $rolesById->get($role->roleId);

                    return sprintf('%04d-%02d', $roleModel->start_year, $roleModel->start_month ?? 1);
                })
                ->values();

            foreach ($orderedExperience as $roleDisplayOrder => $role) {
                $roleModel = $rolesById->get($role->roleId);
                $wordingRole = collect($wordingDraft->experience)->first(fn (RoleWordingDraft $r) => $r->roleId === $role->roleId);

                $experienceRole = $variant->experienceRoles()->create([
                    'role_id' => $role->roleId,
                    'employer_name' => $roleModel->employer->name,
                    'display_title' => $role->displayTitle,
                    'start_year' => $roleModel->start_year,
                    'start_month' => $roleModel->start_month,
                    'end_year' => $roleModel->end_year,
                    'end_month' => $roleModel->end_month,
                    'display_order' => $roleDisplayOrder,
                ]);

                foreach ($role->bulletGroups as $index => $group) {
                    $wordingBullet = $wordingRole === null ? null : collect($wordingRole->bullets)
                        ->first(fn (BulletWordingDraft $b) => $b->bulletGroupIndex === $index);

                    $bulletQualified = collect($selectionDraft->targetTermUsages)->first(
                        fn (TargetTermUsageDraft $usage) => $usage->location === ResumeTermUsageLocation::Bullet
                            && $usage->roleId === $role->roleId
                            && $usage->bulletGroupIndex === $index
                            && $usage->posture === ResumeClaimPosture::Qualified
                    );

                    // Completeness was checked before recovery; a missing
                    // bullet here is one RecoverWording omitted (see the
                    // review report), so it is simply not persisted.
                    if ($wordingBullet === null) {
                        continue;
                    }

                    $baseText = $wordingBullet->text;
                    $finalText = $bulletQualified === null
                        ? $baseText
                        : $this->appendQualifiedClause($baseText, $bulletQualified->relationshipPhraseKey, $bulletQualified->term);

                    $bullet = $experienceRole->bullets()->create([
                        'resume_variant_id' => $variant->id,
                        'project_id' => $group->projectId,
                        'display_order' => $group->order,
                        'text' => $finalText,
                    ]);

                    $bulletIdsByLocation["{$role->roleId}:{$index}"] = $bullet->id;

                    foreach ($group->careerFactKeys as $key) {
                        $bullet->citations()->create(['career_fact_id' => $factIdsByKey[$key]]);
                    }
                }
            }

            foreach ($selectionDraft->summaryEvidenceFactKeys as $key) {
                $variant->summaryEvidence()->create(['career_fact_id' => $factIdsByKey[$key]]);
            }

            foreach ($selectionDraft->skills as $skill) {
                $skillModel = $skillsById->get($skill->skillId);

                $variant->skillSelections()->create([
                    'skill_id' => $skill->skillId,
                    'name' => $skillModel->name,
                    'category' => $skillModel->category->value,
                    'display_order' => $skill->order,
                ]);
            }

            foreach ($educationRecords as $displayOrder => $educationModel) {
                $variant->educationSelections()->create([
                    'education_id' => $educationModel->id,
                    'institution' => $educationModel->institution,
                    'degree' => $educationModel->degree,
                    'field_of_study' => $educationModel->field_of_study,
                    'start_year' => $educationModel->start_year,
                    'end_year' => $educationModel->end_year,
                    'display_order' => $displayOrder,
                ]);
            }

            // Selection's own relevance order — never chronology;
            // independent Projects carry no dates. See
            // docs/domain-model.md "ResumeVariant" -> "Selected Projects".
            $orderedSelectedProjects = collect($selectionDraft->selectedProjects)->sortBy('order')->values();

            foreach ($orderedSelectedProjects as $projectDisplayOrder => $project) {
                $projectModel = $selectedProjectsById->get($project->projectId);
                $wordingProject = collect($wordingDraft->selectedProjects)->first(fn (ProjectWordingDraft $p) => $p->projectId === $project->projectId);

                // A highlight RecoverWording omitted is not persisted.
                if ($wordingProject === null) {
                    continue;
                }

                // Technology names are never model-authored (neither
                // Selection nor Wording write them) — deterministically
                // derived here from the cited facts' own attached
                // Skills, in first-appearance order across the cited
                // facts, deduplicated by name. See
                // docs/domain-model.md "ResumeVariant" -> "Selected
                // Projects".
                $technologyNames = [];
                foreach ($project->careerFactKeys as $key) {
                    // Skills attached to Restricted evidence can name
                    // confidential systems or endpoints, so a highlight
                    // never lists technologies derived from them.
                    if ($factsByKey->get($key)['visibility'] === 'restricted') {
                        continue;
                    }

                    foreach ($factsByKey->get($key)['skills'] as $skill) {
                        if (! in_array($skill['name'], $technologyNames, true)) {
                            $technologyNames[] = $skill['name'];
                        }
                    }
                }

                $projectRow = $variant->projects()->create([
                    'project_id' => $project->projectId,
                    'name' => $projectModel->name,
                    'technology_names' => $technologyNames,
                    'live_url' => $projectModel->live_url,
                    'repository_url' => $projectModel->repository_url,
                    'display_order' => $projectDisplayOrder,
                ]);

                // Wording's own completeness check
                // (assertSelectedProjectsCompleteness in
                // ResumeWordingResponseValidator) already guarantees a
                // matching entry exists for every approved project by
                // the time persistence runs — trusted, not re-guarded
                // here, mirroring how Experience bullets are trusted
                // above.
                foreach ($wordingProject->bullets as $bulletDisplayOrder => $text) {
                    $projectBullet = $projectRow->bullets()->create([
                        'display_order' => $bulletDisplayOrder,
                        'text' => $text,
                    ]);

                    foreach ($project->careerFactKeys as $key) {
                        $projectBullet->citations()->create(['career_fact_id' => $factIdsByKey[$key]]);
                    }
                }
            }

            foreach ($selectionDraft->targetTermUsages as $usage) {
                $bulletId = $usage->location === ResumeTermUsageLocation::Bullet
                    ? ($bulletIdsByLocation["{$usage->roleId}:{$usage->bulletGroupIndex}"] ?? null)
                    : null;

                // A term usage located at an omitted bullet describes a
                // claim that is not in the resume.
                if ($usage->location === ResumeTermUsageLocation::Bullet && $bulletId === null) {
                    continue;
                }

                $usageRow = $variant->targetTermUsages()->create([
                    'bullet_id' => $bulletId,
                    'job_analysis_finding_id' => $usage->jobAnalysisFindingId,
                    'target_term' => $usage->term,
                    'posture' => $usage->posture,
                    'location' => $usage->location,
                    'relationship_phrase_key' => $usage->relationshipPhraseKey,
                ]);

                foreach ($usage->careerFactKeys as $key) {
                    $usageRow->evidence()->create(['career_fact_id' => $factIdsByKey[$key]]);
                }
            }

            // A newly written resume for a job is the one being worked on; a resume the user marked final stays so.
            if ($analysis !== null && $synthesis !== null) {
                app(JobResumes::class)->setCurrent($analysis->jobPosting, $variant);
            }

            return $variant;
        });
    }

    /**
     * The single authoritative map of a Role's legal, model-facing
     * title representations: `'full'` (the complete canonical title,
     * verbatim) plus `'segment_N'` for each of its `"/"`-delimited,
     * trimmed segments in order — present only when the title actually
     * contains a `"/"`, so a role with none (e.g. "Developer Support
     * Engineer") legally offers only `['full' => ...]`, never a
     * `segment_1` key equal to the same string. Selection returns only
     * the key; the real string is resolved from this exact map after
     * validation — never re-derived, re-cased, or fuzzy-matched. See
     * docs/resume-variant-generation.md "Live evaluation".
     *
     * @return array<string, string>
     */
    private function titleChoices(string $title): array
    {
        $segments = array_map('trim', explode('/', $title));

        if (count($segments) === 1) {
            return ['full' => $title];
        }

        $choices = ['full' => $title];

        foreach ($segments as $index => $segment) {
            $choices['segment_'.($index + 1)] = $segment;
        }

        return $choices;
    }

    /**
     * The flat, global set of title_choice tokens the Selection schema
     * offers — derived from the actual candidate roles for this run
     * (never an arbitrary permanent cap): `full`, plus `segment_1`
     * through the highest segment count any candidate role actually
     * has. A role with fewer segments than the global maximum simply
     * doesn't have the higher-numbered keys in its own
     * $titleChoicesByRole entry — the validator (not the schema) is
     * what rejects an out-of-range choice for a specific role, exactly
     * as project_id's global enum already relies on the validator for
     * per-role validity.
     *
     * @param  array<int, array<string, string>>  $titleChoicesByRole
     * @return array<int, string>
     */
    private function titleChoiceKeyEnum(array $titleChoicesByRole): array
    {
        $maxSegments = 0;

        foreach ($titleChoicesByRole as $choices) {
            $maxSegments = max($maxSegments, count($choices) - 1);
        }

        $keys = ['full'];

        for ($i = 1; $i <= $maxSegments; $i++) {
            $keys[] = "segment_{$i}";
        }

        return $keys;
    }

    /**
     * Every CareerFact eligible as Experience-bullet evidence for each
     * Role, computed from real, raw attribution — never inferred from
     * fact text, names, themes, or model output. A fact is eligible for
     * a given Role iff it is attributed directly to that Role, directly
     * to one of that Role's own Projects, or directly to the Employer
     * that owns that Role (an Employer-level fact is therefore eligible
     * for every Role at that Employer, not just one). CareerProfile-
     * attributed facts are deliberately never included in any Role's
     * eligibility set — see docs/resume-variant-generation.md "Design
     * boundary: selection vs. provenance" for why: the current canonical
     * model cannot distinguish a universally-reusable career-wide fact
     * from one describing personal/independent work that would mislead
     * if presented as evidence for paid employment. This has no effect
     * on CareerProfile-attributed facts' eligibility for
     * `summary_evidence` or Skills — both remain governed entirely by
     * `ResumeEligibility`, unrelated to this method.
     *
     * @param  Collection<int, Role>  $roles  Already loaded with their own `projects`.
     * @return array<int, array<string, true>> role_id => set of eligible CareerFact keys.
     */
    private function eligibleFactKeysForRole(Collection $roles, CareerProfile $profile): array
    {
        return app(RoleEligibleFacts::class)->forRoles($roles, $profile);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<string, string>>  $titleChoicesByRole
     * @param  array<string, int>  $projectIdByFactKey
     */
    private function toSelectionDraft(array $validated, array $titleChoicesByRole, array $projectIdByFactKey): ResumeSelectionDraft
    {
        /** @var array<int, string> $summaryEvidence */
        $summaryEvidence = $validated['summary_evidence'];
        /** @var array<int, array<string, mixed>> $skills */
        $skills = $validated['skills'];
        /** @var array<int, array<string, mixed>> $experience */
        $experience = $validated['experience'];
        /** @var array<int, array<string, mixed>> $selectedProjects */
        $selectedProjects = $validated['selected_projects'];
        /** @var array<int, array<string, mixed>> $targetTermUsages */
        $targetTermUsages = $validated['target_term_usages'];

        return new ResumeSelectionDraft(
            summaryEvidenceFactKeys: $summaryEvidence,
            skills: array_map(fn (array $s) => new SkillSelectionDraft($s['skill_id'], $s['order']), $skills),
            experience: array_map(fn (array $r) => new RoleSelectionDraft(
                roleId: $r['role_id'],
                // Trusted, not re-checked: ResumeSelectionResponseValidator
                // already confirmed this exact (role_id, title_choice) pair
                // is legal before persistence ever runs — resolving here
                // reads the real canonical string, never the model's own
                // text (it never supplied any).
                displayTitle: $titleChoicesByRole[$r['role_id']][$r['title_choice']],
                bulletGroups: array_map(fn (array $g) => new BulletGroupDraft(
                    projectId: $this->deriveBulletGroupProjectId($g['career_fact_keys'], $projectIdByFactKey),
                    order: $g['order'],
                    careerFactKeys: $g['career_fact_keys'],
                    jobAnalysisFindingIds: $g['job_analysis_finding_ids'],
                ), $r['bullet_groups']),
            ), $experience),
            selectedProjects: array_map(fn (array $p) => new ProjectSelectionDraft(
                projectId: $p['project_id'],
                order: $p['order'],
                careerFactKeys: $p['career_fact_keys'],
            ), $selectedProjects),
            targetTermUsages: array_map(fn (array $u) => new TargetTermUsageDraft(
                term: $u['term'],
                jobAnalysisFindingId: $u['job_analysis_finding_id'],
                posture: ResumeClaimPosture::from($u['posture']),
                location: ResumeTermUsageLocation::from($u['location_type']),
                roleId: $u['role_id'] === -1 ? null : $u['role_id'],
                bulletGroupIndex: $u['bullet_group_index'] === -1 ? null : $u['bullet_group_index'],
                relationshipPhraseKey: ResumeQualifiedPhrase::from($u['relationship_phrase_key']),
                careerFactKeys: $u['career_fact_keys'],
            ), $targetTermUsages),
        );
    }

    /**
     * The deterministic replacement for a model-declared bullet-group
     * `project_id` (removed from the schema as of
     * ResumeSelectionPromptV3 — see that class's own docblock and
     * docs/resume-variant-generation.md "Design boundary: selection vs.
     * provenance"): every CareerFact already carries its own real,
     * canonical Project attribution, so a bullet's project is fully
     * determined by which facts Selection grouped into it — never
     * guessed from names, themes, role membership, ordering, or
     * similarity.
     *
     * - Every cited fact shares the same non-null project -> that project.
     * - Any cited fact is role-level (no project), or the cited facts
     *   span more than one project -> no specific project (`null`, the
     *   same internal representation the old `-1` sentinel resolved to).
     *
     * `assertBulletGroupCareerFactsAreEligibleForRole()` has already
     * confirmed every key here is eligible evidence for this bullet's
     * role by the time this runs; this method only narrows further, to
     * at most one project within that role.
     *
     * @param  array<int, string>  $careerFactKeys
     * @param  array<string, int>  $projectIdByFactKey
     */
    private function deriveBulletGroupProjectId(array $careerFactKeys, array $projectIdByFactKey): ?int
    {
        $projectIds = array_unique(array_map(
            fn (string $key) => $projectIdByFactKey[$key] ?? null,
            $careerFactKeys,
        ));

        if (count($projectIds) === 1 && $projectIds[0] !== null) {
            return $projectIds[0];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $selection  Validated Selection response.
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @return array<int, array{skill_id: int, order: int}>
     */
    private function deriveSkillsFromSelectedEvidence(array $selection, Collection $factsByKey): array
    {
        $citedKeys = array_merge(
            $selection['summary_evidence'] ?? [],
            ...array_map(
                fn (array $role) => array_merge(...array_map(fn (array $g) => $g['career_fact_keys'], $role['bullet_groups'] ?? []) ?: [[]]),
                $selection['experience'] ?? [],
            ),
            ...array_map(fn (array $p) => $p['career_fact_keys'], $selection['selected_projects'] ?? []) ?: [[]],
        );

        $counts = [];
        $names = [];

        foreach ($citedKeys as $key) {
            $fact = $factsByKey->get($key);

            if ($fact === null || $fact['visibility'] === 'restricted') {
                continue;
            }

            foreach ($fact['skills'] as $skill) {
                $counts[$skill['id']] = ($counts[$skill['id']] ?? 0) + 1;
                $names[$skill['id']] = $skill['name'];
            }
        }

        uksort($counts, fn (int $a, int $b) => [$counts[$b], $names[$a]] <=> [$counts[$a], $names[$b]]);

        $order = 0;

        return array_map(
            fn (int $id) => ['skill_id' => $id, 'order' => ++$order],
            array_slice(array_keys($counts), 0, 16),
        );
    }

    /**
     * @param  array<mixed>  $content
     * @return array<mixed>
     */
    private function cleanStrings(array $content): array
    {
        return array_map(fn (mixed $value) => match (true) {
            is_string($value) => ResumeText::clean($value),
            is_array($value) => $this->cleanStrings($value),
            default => $value,
        }, $content);
    }

    /**
     * Whether everything that blocked an item is an unsupported claim (scope, cause, role, qualifier, delivery stage), as
     * opposed to protected content, so the claim's own wording is safe to show next to its repair.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function claimsOnly(array $findings): bool
    {
        $blocks = array_filter($findings, fn (array $f) => $f['severity'] === 'block');

        return $blocks !== [] && array_filter($blocks, fn (array $f) => ! in_array($f['kind'], ClaimFidelityChecker::KINDS, true)) === [];
    }

    /**
     * The human-readable account of what happened to each generated
     * location: which passed, which were generalized or omitted, and
     * which need verification, each with the evidence behind it. Holds
     * finding kinds, terms and figures, and the supporting facts' own
     * text, but never the text of an omitted or blocked claim.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @param  \Illuminate\Database\Eloquent\Collection<int, Role>  $roles
     * @return array<string, mixed>
     */
    private function buildReviewReport(array $items, Collection $factsByKey, Collection $roles): array
    {
        $rolesById = $roles->load('employer')->keyBy('id');
        $projectNames = Project::query()
            ->whereIn('id', array_filter(array_column($items, 'project_id')))
            ->pluck('name', 'id');
        $counts = ['passed' => 0, 'review' => 0, 'generalized' => 0, 'omitted' => 0];
        $report = [];

        foreach ($items as $item) {
            $counts[$item['status']]++;
            $flagged = $item['status'] !== 'passed';

            $role = $item['location'] === 'experience' ? $rolesById->get($item['role_id']) : null;

            $label = match ($item['location']) {
                'summary' => 'Summary',
                'profile' => 'Approved profile coverage',
                'project' => 'Project: '.($projectNames[$item['project_id']] ?? $item['project_id']),
                default => $role === null
                    ? 'Role '.$item['role_id'].', bullet '.($item['bullet_group_index'] + 1)
                    : "{$role->employer->name}, {$role->title}, bullet ".($item['bullet_group_index'] + 1),
            };

            $report[] = [
                'location' => $item['location'],
                'label' => $label,
                'status' => $item['status'],
                'role_id' => $item['role_id'] ?? null,
                'bullet_group_index' => $item['bullet_group_index'] ?? null,
                'project_id' => $item['project_id'] ?? null,
                'needs_verification' => in_array($item['status'], ['review', 'generalized'], true),
                // The exact sentence under review (for an omitted item, the text that was removed).
                'text' => $flagged ? ($item['text'] ?? null) : null,
                // What the repair started from, so a person can compare. Only where every blocking finding is an unsupported
                // claim: text blocked for restricted or confidential detail is never kept here.
                'original' => $flagged && $this->claimsOnly($item['findings']) ? ($item['original'] ?? null) : null,
                'findings' => array_map(fn (array $f) => ['kind' => $f['kind'], 'severity' => $f['severity'], 'detail' => $f['detail'], 'message' => $f['message']], $item['findings']),
                'action' => $item['action'],
                'evidence' => array_map(fn (string $key) => array_filter([
                    'fact_key' => $key,
                    'visibility' => $factsByKey->get($key)['visibility'] ?? null,
                    // The supporting statement is included only where a
                    // person has something to verify.
                    'statement' => $flagged ? ($factsByKey->get($key)['statement'] ?? null) : null,
                ], fn ($v) => $v !== null), $item['fact_keys']),
            ];
        }

        return ['version' => 1, 'counts' => $counts, 'items' => $report];
    }

    /**
     * Calls Selection, repeating the identical call (up to $maxCalls in
     * total) only when the model returned no final content. Any other
     * failure propagates immediately.
     *
     * @param  \Closure(): ResumeSelectionProviderResponse  $call
     */
    private function selectWithEmptyOutputRetry(GenerationMetrics $metrics, int $maxCalls, \Closure $call): ResumeSelectionProviderResponse
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $metrics->measure('selection', $call, fn (ResumeSelectionProviderResponse $response) => $response->usage);
            } catch (ResumeEmptyOutputException $e) {
                $this->recordEmptyOutput($metrics, 'selection', $attempt, $e);

                if ($attempt >= $maxCalls) {
                    throw $this->exhaustedEmptyOutput('Selection', $attempt, $e);
                }
            }
        }
    }

    private function recordEmptyOutput(GenerationMetrics $metrics, string $stage, int $call, ResumeEmptyOutputException $e): void
    {
        $context = [
            'stage' => $stage,
            'call' => $call,
            'kind' => 'empty_output',
            'finish_reason' => $e->diagnostics?->finishReason,
            'prompt_tokens' => $e->diagnostics?->usage['prompt_tokens'] ?? null,
            'completion_tokens' => $e->diagnostics?->usage['completion_tokens'] ?? null,
            'reasoning_field_present' => $e->reasoningPresent,
            'reasoning_field_chars' => $e->reasoningChars,
        ];

        $metrics->event($context);
        Log::warning('Resume generation received no final content from the model.', $context);
    }

    private function exhaustedEmptyOutput(string $stage, int $calls, ResumeEmptyOutputException $last): ResumeEmptyOutputException
    {
        return new ResumeEmptyOutputException(
            "Resume {$stage} provider returned no final content after {$calls} ".($calls === 1 ? 'attempt' : 'attempts').'.',
            $last->diagnostics,
            $last,
            $last->reasoningPresent,
            $last->reasoningChars,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function toWordingDraft(array $validated): ResumeWordingDraft
    {
        /** @var array<int, array<string, mixed>> $experience */
        $experience = $validated['experience'];
        /** @var array<int, array<string, mixed>> $selectedProjects */
        $selectedProjects = $validated['selected_projects'];

        return new ResumeWordingDraft(
            summary: $validated['summary'],
            experience: array_map(fn (array $r) => new RoleWordingDraft(
                roleId: $r['role_id'],
                bullets: array_map(fn (array $b) => new BulletWordingDraft($b['bullet_group_index'], $b['text']), $r['bullets']),
            ), $experience),
            selectedProjects: array_map(fn (array $p) => new ProjectWordingDraft(
                projectId: $p['project_id'],
                bullets: [$p['text']],
            ), $selectedProjects),
        );
    }

    /**
     * Re-hydrates Stage 1's fact-key references with their real
     * canonical text for Stage 2's prompt — Stage 2 never sees raw
     * keys it would need to look anything up from. Also attaches each
     * location's own approved direct target terms (`direct_target_terms`/
     * `summary_direct_target_terms`) — see
     * buildDirectTargetTermsByLocation()'s own docblock — and the
     * Summary's own closed-world canonical-Skill allow-list
     * (`summary_authorized_skills`) — see the call site in
     * generateFull() for how it's computed. Selected Projects never
     * receive either field: `ResumeTermUsageLocation` has no "project"
     * case, so a target-term usage can never be located at one, and
     * `summary_authorized_skills` is deliberately scoped to the
     * Summary only in this milestone — nothing to attach at a Selected
     * Project for either.
     *
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @param  array<string, array<int, string>>  $directTargetTermsByLocation
     * @param  array<int, string>  $summaryAuthorizedSkillNames
     * @return array<string, mixed>
     */
    private function buildWordingInput(ResumeSelectionDraft $draft, Collection $factsByKey, array $directTargetTermsByLocation, array $summaryAuthorizedSkillNames): array
    {
        return [
            'summary_evidence' => collect($draft->summaryEvidenceFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
            'summary_authorized_skills' => $summaryAuthorizedSkillNames,
            'summary_direct_target_terms' => $directTargetTermsByLocation['summary'] ?? [],
            'experience' => collect($draft->experience)->map(fn (RoleSelectionDraft $role) => [
                'role_id' => $role->roleId,
                'display_title' => $role->displayTitle,
                'bullet_groups' => collect($role->bulletGroups)->map(fn (BulletGroupDraft $group, int $index) => [
                    'bullet_group_index' => $index,
                    'career_facts' => collect($group->careerFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
                    // A group of several facts is one accomplishment; each fact's part in it (built, implementation,
                    // deployment, outcome) tells the wording stage what the bullet can say.
                    ...(count($group->careerFactKeys) > 1 ? ['fact_roles' => collect($group->careerFactKeys)->mapWithKeys(fn (string $key, int $n) => [$key => StoryRole::of((string) ($factsByKey->get($key)['statement'] ?? ''), $n === 0)])->all()] : []),
                    'direct_target_terms' => $directTargetTermsByLocation["{$role->roleId}:{$index}"] ?? [],
                ])->values()->all(),
            ])->values()->all(),
            'selected_projects' => collect($draft->selectedProjects)->map(fn (ProjectSelectionDraft $project) => [
                'project_id' => $project->projectId,
                'career_facts' => collect($project->careerFactKeys)->map(fn (string $key) => $factsByKey->get($key))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * The target_term_usages -> Resume Wording handoff, designed as
     * fact-local, location-scoped guidance rather than a global list —
     * see the target_term_usages investigation and
     * docs/resume-variant-generation.md "Target-term location
     * integrity". Only `direct`-posture usages are represented:
     * `qualified` terms are never surfaced to Wording at all (the term
     * is appended deterministically after generation by
     * appendQualifiedClause() — Wording never writes it), and
     * `capability` terms remain fully prohibited via `denylistTerms`
     * (see buildDenylistTerms()) — neither belongs in a list meant to
     * encourage a term's appearance.
     *
     * Deliberately does NOT use each usage's own `careerFactKeys` —
     * that would open a second, independent evidence channel alongside
     * the fact-local evidence Wording already receives per location,
     * which is exactly the risk this design avoids. Only `term` and
     * `location` are used: the location says WHERE a term is
     * authorized: the evidence already supplied there (via
     * buildWordingInput()) says WHAT the model may draw on to use it.
     *
     * @return array<string, array<int, string>> "summary" or "role_id:index" => term[] approved there.
     */
    private function buildDirectTargetTermsByLocation(ResumeSelectionDraft $draft): array
    {
        $byLocation = [];

        foreach ($draft->targetTermUsages as $usage) {
            if ($usage->posture !== ResumeClaimPosture::Direct) {
                continue;
            }

            $key = $usage->location === ResumeTermUsageLocation::Bullet
                ? "{$usage->roleId}:{$usage->bulletGroupIndex}"
                : 'summary';

            $byLocation[$key][] = $usage->term;
        }

        return $byLocation;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function buildCareerFactKeysByLocation(ResumeSelectionDraft $draft): array
    {
        $byLocation = ['summary' => $draft->summaryEvidenceFactKeys];

        foreach ($draft->experience as $role) {
            foreach ($role->bulletGroups as $index => $group) {
                $byLocation["{$role->roleId}:{$index}"] = $group->careerFactKeys;
            }
        }

        foreach ($draft->selectedProjects as $project) {
            $byLocation["project:{$project->projectId}"] = $project->careerFactKeys;
        }

        foreach ($draft->targetTermUsages as $usage) {
            $key = $usage->location === ResumeTermUsageLocation::Bullet
                ? "{$usage->roleId}:{$usage->bulletGroupIndex}"
                : 'summary';

            $byLocation[$key] = array_values(array_unique([...($byLocation[$key] ?? []), ...$usage->careerFactKeys]));
        }

        return $byLocation;
    }

    /**
     * Applies the duplicate check to the WRITTEN bullets (CompositionChecks::resolveDuplicates) and records what it found with
     * the tailoring provenance, where the revision tool already looks for suggestions.
     *
     * @param  array{content: array<string, mixed>, items: array<int, array<string, mixed>>}  $recovered
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @param  array<string, mixed>|null  $tailoring
     * @param  array<string, mixed>|null  $relevance
     * @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function withoutDuplicates(array $recovered, ResumeSelectionDraft $draft, Collection $factsByKey, ?array &$tailoring, ?array $relevance): array
    {
        $keysAt = [];
        $onResume = $draft->summaryEvidenceFactKeys;

        foreach ($draft->experience as $role) {
            foreach ($role->bulletGroups as $index => $group) {
                $keysAt["{$role->roleId}:{$index}"] = $group->careerFactKeys;
                $onResume = [...$onResume, ...$group->careerFactKeys];
            }
        }

        // Eligible, shareable facts that speak to the job and are not on the resume, best first: what could take a removed bullet's place.
        $unused = array_keys(array_filter((array) ($relevance['facts'] ?? []), fn (array $f, string $key) => $f['score'] >= 0.4 && ! in_array($key, $onResume, true) && ($factsByKey->get($key)['visibility'] ?? null) === 'public', ARRAY_FILTER_USE_BOTH));
        usort($unused, fn (string $a, string $b) => $relevance['facts'][$b]['score'] <=> $relevance['facts'][$a]['score']);

        $resolved = app(CompositionChecks::class)->resolveDuplicates($recovered, $keysAt, $factsByKey->all(), $unused);

        if ($tailoring !== null && $resolved['findings'] !== []) {
            $tailoring['composition'] = [...(array) ($tailoring['composition'] ?? []), ...$resolved['findings']];
        }

        return ['content' => $resolved['content'], 'items' => $resolved['items']];
    }

    /**
     * The facts a job-tailored summary may draw on. The summary is judged against exactly these (a technology or a figure
     * it names must be stated by one of them), so they are what a summary should be built from: what this candidate
     * built and the technologies it used, not the claims every resume makes.
     *
     *   identity      the profile's own leading facts, except general ownership or leadership claims;
     *   strongest     the whole bullet groups of the three strongest accomplishments and the five facts that speak most to
     *                 the job, so a technology the summary names is stated by a fact it cites;
     *   support-only  a first-person fact the job asks for.
     *
     * Left out on purpose: a general claim of ownership or leadership (it would write "end-to-end ownership" for everyone),
     * and any fact that states a figure which a bullet already carries, so a distinctive result is told once, in the bullet
     * that earns it, and the summary is not offered the number to repeat.
     *
     * @param  array<string, mixed>  $content  the deterministic selection
     * @param  array<string, mixed>  $effective  the job-adapted profile
     * @param  array<string, mixed>  $relevance  JobRelevance::analyze()
     * @param  array<string, array<string, mixed>>  $offered  the offered career facts by key
     * @return array<int, string>
     */
    private function jobSummaryEvidence(array $content, array $effective, array $relevance, array $offered): array
    {
        $score = fn (string $key): float => (float) ($relevance['facts'][$key]['score'] ?? 0.0);
        $general = fn (string $key): bool => JobRelevance::isGeneralClaim($relevance, $key);
        $groups = [];
        $selected = [];

        foreach ($content['experience'] as $role) {
            foreach ($role['bullet_groups'] as $group) {
                $groups[] = ['keys' => $group['career_fact_keys'], 'score' => max([0.0, ...array_map($score, $group['career_fact_keys'])])];
                $selected = [...$selected, ...$group['career_fact_keys']];
            }
        }

        $selected = array_values(array_unique($selected));
        // A figure is told in the bullet that carries it; a fact stating one that a bullet already states is not offered to the summary.
        $carriesFigure = fn (string $key): bool => preg_match('/\d/', (string) ($offered[$key]['statement'] ?? '')) === 1;
        $usable = fn (string $key): bool => ! $general($key) && ! ($carriesFigure($key) && in_array($key, $selected, true));

        usort($groups, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $strongest = array_values(array_filter(array_merge([], ...array_map(fn (array $g) => $g['keys'], array_slice($groups, 0, 3))), $usable));
        $byRelevance = array_values(array_filter($selected, $usable));
        usort($byRelevance, fn (string $a, string $b) => $score($b) <=> $score($a));
        $supportOnly = array_values(array_filter($effective['presentation']['summary_fact_keys'], fn (string $k) => isset($offered[$k]) && ($effective['facts'][$k]['support_only'] ?? false)));
        $identity = array_values(array_filter(array_slice($effective['presentation']['summary_fact_keys'], 0, 4), fn (string $k) => isset($offered[$k]) && $usable($k)));

        return array_values(array_unique([...array_slice($identity, 0, 2), ...$strongest, ...array_slice($byRelevance, 0, 5), ...$supportOnly]));
    }

    /**
     * What the wording stage is told about the job: its role and emphasis, what the candidate's approved evidence
     * supports directly, and what it does not (never to be claimed or hinted at).
     *
     * @param  array<string, mixed>  $relevance  JobRelevance::analyze()
     * @param  array<string, array<string, mixed>>  $factsByKey  the offered career facts
     * @return array<string, mixed>
     */
    private function jobContext(JobAnalysis $analysis, array $relevance, array $factsByKey): array
    {
        $emphasis = array_slice(array_values(array_map(
            fn (array $f) => ['requirement' => $f['statement'], 'kind' => $f['category']],
            array_filter($relevance['findings'], fn (array $f) => in_array($f['category'], ['responsibility', 'required_qualification', 'technology', 'preferred_qualification'], true)),
        )), 0, 12);
        $gapLabels = array_column($relevance['gaps'], 'label');
        $supported = [];
        $byWeight = $relevance['capabilities'];
        arsort($byWeight);

        foreach ($byWeight as $cap => $weight) {
            if (! in_array((string) config("resume_profiles.capabilities.{$cap}.label", $cap), $gapLabels, true)) {
                $supported[] = (string) config("resume_profiles.capabilities.{$cap}.label", $cap);
            }
        }

        return [
            'role' => $analysis->jobPosting->title, 'company' => $analysis->jobPosting->company, 'summary' => $analysis->role_summary,
            'emphasis' => $emphasis, 'supported' => $supported, 'lead_with' => array_slice($supported, 0, 5), 'not_supported' => $gapLabels,
        ];
    }

    /**
     * Every target term EXCEPT those with an approved `direct` posture
     * usage anywhere — those may only ever enter Stage 2's free text
     * through the deterministic qualified-clause mechanism, never as
     * free prose, even for terms Selection did approve a `qualified`/
     * `capability` claim for.
     *
     * @param  array<int, string>  $validTargetTerms
     * @return array<int, string>
     */
    private function buildDenylistTerms(array $validTargetTerms, ResumeSelectionDraft $draft): array
    {
        $directTerms = collect($draft->targetTermUsages)
            ->filter(fn (TargetTermUsageDraft $usage) => $usage->posture === ResumeClaimPosture::Direct)
            ->pluck('term')
            ->all();

        return array_values(array_diff($validTargetTerms, $directTerms));
    }

    private function appendQualifiedClause(string $baseText, ResumeQualifiedPhrase $phrase, string $term): string
    {
        $trimmed = rtrim($baseText);
        $withoutTrailingPeriod = str_ends_with($trimmed, '.') ? rtrim(substr($trimmed, 0, -1)) : $trimmed;

        return "{$withoutTrailingPeriod}, {$phrase->render()} {$term}.";
    }
}
