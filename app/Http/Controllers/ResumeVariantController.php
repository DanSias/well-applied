<?php

namespace App\Http\Controllers;

use App\Enums\GenerationType;
use App\Enums\JobDisposition;
use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeTermUsageLocation;
use App\Jobs\GenerateResumeVariantJob;
use App\Models\CareerFact;
use App\Models\Education;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\ResumeVariantExperienceRole;
use App\Models\ResumeVariantTargetTermUsage;
use App\Models\Skill;
use App\Support\GenerationAttempt\QueueGenerationAttempt;
use App\Support\JobFreshness\JobFreshness;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\Navigation\JobHome;
use App\Support\ReadinessReview\PresentReadiness;
use App\Support\ResumeRevision\PresentResumeWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generates and displays ResumeVariant snapshots for one JobMatch.
 * Generation always creates a new immutable snapshot — never edits or
 * replaces a prior one, and there is no "current" pointer to manage.
 * No editing, no deletion, no PDF — a read-only inspection surface over
 * App\Support\ResumeVariant\GenerateResumeVariant's pipeline, mirroring
 * JobMatchController exactly.
 *
 * store() itself never calls the generation pipeline — it only
 * creates/looks up a GenerationAttempt and dispatches
 * GenerateResumeVariantJob, which owns the actual
 * GenerateResumeVariant::generateFull() call and every outcome durably
 * on that attempt. See "Async Resume" in docs/resume-variant-generation.md.
 */
class ResumeVariantController extends Controller
{
    public function store(
        JobPosting $jobPosting,
        JobAnalysis $jobAnalysis,
        JobMatch $jobMatch,
        QueueGenerationAttempt $claims,
    ): RedirectResponse {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);
        abort_unless($jobMatch->job_analysis_id === $jobAnalysis->id, 404);
        JobFreshness::abortUnlessNewWorkAllowed($jobPosting);

        // Atomic duplicate-safe claim, shared with Job Analysis / Job Match.
        $claims->claim(GenerationType::ResumeVariant, $jobMatch);

        return redirect()->route('jobs.analyses.matches.show', [$jobPosting, $jobAnalysis, $jobMatch]);
    }

    public function show(
        JobPosting $jobPosting,
        JobAnalysis $jobAnalysis,
        JobMatch $jobMatch,
        ResumeVariant $resumeVariant,
    ): Response {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);
        abort_unless($jobMatch->job_analysis_id === $jobAnalysis->id, 404);
        abort_unless($resumeVariant->job_match_id === $jobMatch->id, 404);

        $resumeVariant->load([
            'experienceRoles.bullets.citations.careerFact',
            'experienceRoles.bullets.targetTermUsages',
            'summaryEvidence.careerFact',
            'skillSelections.skill',
            'educationSelections.education',
            'targetTermUsages.jobAnalysisFinding',
            'targetTermUsages.evidence.careerFact',
        ]);

        return Inertia::render('jobs/resumes/show', [
            'job' => [
                'id' => $jobPosting->id,
                'company' => $jobPosting->company,
                'title' => $jobPosting->title,
                'home_url' => JobHome::url($jobPosting),
            ],
            'analysis' => ['id' => $jobAnalysis->id],
            'match' => ['id' => $jobMatch->id],
            'resume' => $this->transformResumeVariant($resumeVariant),
        ]);
    }

    /**
     * The review page of a resume composed from an approved profile for one job: the same snapshot view, plus why each
     * experience is there (the job requirements it answers) and what the job needed that the record does not support.
     */
    public function showForJob(JobPosting $jobPosting, ResumeVariant $resumeVariant): Response
    {
        $resumeVariant->loadMissing('jobAnalysis');
        abort_unless($resumeVariant->jobAnalysis !== null && $resumeVariant->jobAnalysis->job_posting_id === $jobPosting->id, 404);

        $resumeVariant->load([
            'experienceRoles.bullets.citations.careerFact',
            'summaryEvidence.careerFact',
            'skillSelections.skill',
            'educationSelections.education',
        ]);

        $tailoring = (array) ($resumeVariant->selection_input_snapshot['tailoring'] ?? []);
        $labels = collect((array) ($tailoring['findings'] ?? []))->pluck('label', 'id');
        $statements = $resumeVariant->jobAnalysis->findings()->pluck('statement', 'id');
        $factStatements = CareerFact::query()->whereIn('key', [
            ...($tailoring['added_outside_reference'] ?? []), ...($tailoring['dropped_from_reference'] ?? []),
            ...collect((array) ($tailoring['composition'] ?? []))->flatMap(fn (array $f) => [...$f['selected'], ...$f['available']])->all(),
        ])
            ->where('visibility', '!=', 'private')->get()->keyBy('key');
        $groups = [];

        foreach ((array) ($resumeVariant->selection_raw_response['experience'] ?? []) as $role) {
            foreach ($role['bullet_groups'] as $group) {
                $groups[$role['role_id'].':'.$group['order']] = array_values(array_filter(array_map(fn (int $id) => $labels[$id] ?? null, $group['job_analysis_finding_ids'] ?? [])));
            }
        }

        $resume = $this->transformResumeVariant($resumeVariant);
        $resume['experience'] = array_map(function (array $role) use ($resumeVariant, $groups) {
            $roleRow = $resumeVariant->experienceRoles->firstWhere('role_id', $role['role_id']);
            $role['bullets'] = array_map(fn (array $b) => [...$b, 'answers' => $groups[$role['role_id'].':'.($roleRow?->bullets->firstWhere('id', $b['id'])?->display_order)] ?? []], $role['bullets']);

            return $role;
        }, $resume['experience']);

        $describe = fn (string $key) => ['key' => $key, 'statement' => $factStatements[$key]->statement ?? $key];

        return Inertia::render('jobs/resumes/workspace', [
            'job' => ['id' => $jobPosting->id, 'company' => $jobPosting->company, 'title' => $jobPosting->title, 'pursued' => $jobPosting->disposition === JobDisposition::Pursued, 'home_url' => JobHome::url($jobPosting)],
            'analysis' => ['id' => $resumeVariant->job_analysis_id],
            'match' => null,
            'resume' => $resume,
            'workspace' => app(PresentResumeWorkspace::class)->present($jobPosting, $resumeVariant),
            'readiness' => app(PresentReadiness::class)->present($resumeVariant),
            'tailoring' => $tailoring === [] ? null : [
                'profile_version' => $tailoring['profile_version'] ?? null,
                'analysis_prompt_version' => $tailoring['analysis_prompt_version'] ?? null,
                'requirements' => array_map(fn (array $f) => [...$f, 'statement' => $statements[$f['id']] ?? ''], array_slice((array) ($tailoring['findings'] ?? []), 0, 40)),
                'gaps' => array_map(fn (array $g) => ['label' => $g['label'], 'status' => $g['status'], 'finding' => $g['finding'], 'required' => $g['required']], (array) ($tailoring['gaps'] ?? [])),
                'added' => array_map($describe, (array) ($tailoring['added_outside_reference'] ?? [])),
                'dropped' => array_map($describe, (array) ($tailoring['dropped_from_reference'] ?? [])),
                // Whole-resume composition findings. A fact that cannot be shown (Private) is named only by count.
                'composition' => array_map(fn (array $f) => [
                    'check' => $f['check'], 'severity' => $f['severity'], 'message' => $f['message'],
                    'selected' => array_values(array_map($describe, array_filter($f['selected'], fn (string $k) => isset($factStatements[$k])))),
                    'available' => array_values(array_map($describe, array_filter($f['available'], fn (string $k) => isset($factStatements[$k])))),
                ], (array) ($tailoring['composition'] ?? [])),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformResumeVariant(ResumeVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'generated_at' => $variant->generated_at->toDateTimeString(),
            'selection_generated_by' => $variant->selection_generated_by,
            'wording_generated_by' => $variant->wording_generated_by,
            'schema_version' => $variant->schema_version,
            'selection_prompt_version' => $variant->selection_prompt_version,
            'wording_prompt_version' => $variant->wording_prompt_version,
            'summary' => $variant->summary,
            'summary_evidence' => $variant->summaryEvidence
                ->map(fn ($evidence) => $this->transformCareerFactSummary($evidence->careerFact))
                ->all(),
            'summary_target_term_usages' => $variant->targetTermUsages
                ->filter(fn (ResumeVariantTargetTermUsage $usage) => $usage->location === ResumeTermUsageLocation::Summary)
                ->map($this->transformTargetTermUsage(...))
                ->values()
                ->all(),
            'experience' => $this->transformExperienceRoles($variant->experienceRoles),
            'skills' => $variant->skillSelections
                ->sortBy('display_order')
                ->map(fn ($selection) => $this->transformSkill($selection->skill))
                ->values()
                ->all(),
            'education' => $variant->educationSelections
                ->sortBy('display_order')
                ->map(fn ($selection) => $this->transformEducation($selection->education))
                ->values()
                ->all(),
        ];
    }

    /**
     * Reads the frozen role-snapshot rows directly — display_order was
     * already computed deterministically, once, at generation time
     * (reverse-chronological by real canonical start date, exactly
     * like a conventional resume, never by anything the model decided
     * — see GenerateResumeVariant::generateFull()). No live Role/
     * Employer query here: employer name and dates are frozen on the
     * snapshot row itself, so this never re-derives them and never
     * drifts if the live Role/Employer is later edited. See
     * docs/domain-model.md "ResumeVariant" -> "Experience role
     * snapshots".
     *
     * @param  Collection<int, ResumeVariantExperienceRole>  $experienceRoles
     * @return array<int, array<string, mixed>>
     */
    private function transformExperienceRoles(Collection $experienceRoles): array
    {
        return $experienceRoles
            ->sortBy('display_order')
            ->map(fn (ResumeVariantExperienceRole $role) => [
                'role_id' => $role->role_id,
                'employer' => $role->employer_name,
                'display_title' => $role->display_title,
                'start_year' => $role->start_year,
                'start_month' => $role->start_month,
                'end_year' => $role->end_year,
                'end_month' => $role->end_month,
                'bullets' => $role->bullets->sortBy('display_order')->map($this->transformBullet(...))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBullet(ResumeVariantExperienceBullet $bullet): array
    {
        return [
            'id' => $bullet->id,
            'project' => $bullet->project?->name,
            'text' => $bullet->text,
            'citations' => $bullet->citations
                ->map(fn ($citation) => $this->transformCareerFactSummary($citation->careerFact))
                ->all(),
            'target_term_usages' => $bullet->targetTermUsages
                ->map($this->transformTargetTermUsage(...))
                ->values()
                ->all(),
        ];
    }

    /**
     * Visibility is resolved live from the CareerFact relation, never
     * frozen at generation time — same asymmetry as JobMatch. See
     * docs/domain-model.md "ResumeVariant".
     *
     * @return array<string, mixed>
     */
    private function transformCareerFactSummary(CareerFact $fact): array
    {
        $attribution = CareerFactAttribution::resolve($fact);

        return [
            'key' => $fact->key,
            'statement' => $fact->statement,
            'visibility' => $fact->visibility->value,
            'attribution' => [
                'employer' => $attribution->employer,
                'role' => $attribution->role,
                'project' => $attribution->project,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTargetTermUsage(ResumeVariantTargetTermUsage $usage): array
    {
        return [
            'term' => $usage->target_term,
            'posture' => $usage->posture->value,
            'relationship_phrase' => $usage->posture === ResumeClaimPosture::Qualified
                ? $usage->relationship_phrase_key->render()
                : null,
            'job_analysis_finding_statement' => $usage->jobAnalysisFinding->statement,
            'evidence' => $usage->evidence
                ->map(fn ($evidence) => $this->transformCareerFactSummary($evidence->careerFact))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSkill(Skill $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'category' => $skill->category->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEducation(Education $education): array
    {
        return [
            'institution' => $education->institution,
            'degree' => $education->degree,
            'field_of_study' => $education->field_of_study,
            'start_year' => $education->start_year,
            'end_year' => $education->end_year,
        ];
    }
}
