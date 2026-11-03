<?php

namespace Tests\Support;

use App\Enums\CareerFactType;
use App\Enums\DescriptionCompleteness;
use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerFactMatch;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\JobPosting;
use App\Models\Project;
use App\Models\Role;

/**
 * A small payments-integration scenario shaped like the Boldin case: a
 * required webhook qualification and a billing responsibility that are only
 * partly evidenced, an unevidenced preferred Rails item, a supported
 * front-end item, and a culture signal that can never drive a question.
 */
final class ClarificationFixtures
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public static function scenario(): array
    {
        $profile = CareerProfile::query()->oldest('id')->first() ?? CareerProfile::factory()->create();
        $suffix = self::$sequence === 0 ? '' : '-'.self::$sequence;
        $employer = Employer::factory()->create(['career_profile_id' => $profile->id, 'name' => 'RocketGate']);
        $role = Role::factory()->create(['employer_id' => $employer->id, 'title' => 'Developer Support Engineer', 'start_year' => 2025, 'start_month' => 5]);
        $olderEmployer = Employer::factory()->create(['career_profile_id' => $profile->id, 'name' => 'Pearson']);
        $olderRole = Role::factory()->create(['employer_id' => $olderEmployer->id, 'title' => 'Analyst', 'start_year' => 2019, 'start_month' => 1, 'end_year' => 2023, 'end_month' => 6]);
        $project = Project::factory()->create(['role_id' => $role->id, 'name' => 'Remediation Tooling', 'slug' => 'remediation-tooling-'.self::$sequence]);

        $publicFact = self::fact($profile, 'rg-merchant-integration'.$suffix, 'Served as the technical point of contact for new merchants and troubleshot integration issues.', Visibility::Public, $role);
        $restrictedFact = self::fact($profile, 'rg-remediation-resumable'.$suffix, 'Built resumable, idempotent live-API correction tooling.', Visibility::Restricted, $project);
        $privateFact = self::fact($profile, 'rg-private-note'.$suffix, 'A private note about a client that must never be shown.', Visibility::Private, $role);
        $olderFact = self::fact($profile, 'pearson-migration'.$suffix, 'Collaborated on a reporting data migration.', Visibility::Public, $olderRole);

        $posting = JobPosting::factory()->create([
            'career_profile_id' => $profile->id,
            'description_completeness' => DescriptionCompleteness::Complete,
        ]);
        $analysis = JobAnalysis::factory()->create(['job_posting_id' => $posting->id, 'prompt_version' => 'job-analysis-v4']);
        $match = JobMatch::factory()->create([
            'job_analysis_id' => $analysis->id,
            'career_profile_id' => $profile->id,
            'prompt_version' => 'job-match-v3',
            'evidence_revision' => (int) $profile->evidence_revision,
        ]);

        $webhook = self::finding($analysis, $match, JobAnalysisFindingCategory::RequiredQualification, 'webhook_integration_experience',
            'Strong experience building webhook-driven, third-party API integrations, with sound reasoning about failure handling, retries, and idempotency.',
            JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, JobMatchCoverage::Partial,
            [[$publicFact, MatchRelationship::Direct], [$restrictedFact, MatchRelationship::Contextual], [$privateFact, MatchRelationship::Direct]]);

        $billing = self::finding($analysis, $match, JobAnalysisFindingCategory::Responsibility, 'payments_migration',
            'Contribute to an active payments migration and help simplify a multi-gateway billing setup.',
            null, JobAnalysisEmphasis::High, JobMatchCoverage::Partial,
            [[$olderFact, MatchRelationship::Transferable]]);

        $rails = self::finding($analysis, $match, JobAnalysisFindingCategory::PreferredQualification, 'rails_experience',
            'Ruby on Rails experience.',
            JobAnalysisRequirementStrength::Preferred, JobAnalysisEmphasis::Normal, JobMatchCoverage::NoEvidence, []);

        $react = self::finding($analysis, $match, JobAnalysisFindingCategory::RequiredQualification, 'frontend_proficiency',
            'Working proficiency with React.',
            JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, JobMatchCoverage::Supported,
            [[$publicFact, MatchRelationship::Direct]]);

        $culture = self::finding($analysis, $match, JobAnalysisFindingCategory::CultureSignal, 'mission_driven',
            'A mission-driven company.', null, JobAnalysisEmphasis::Normal, JobMatchCoverage::NoEvidence, []);

        self::$sequence++;

        return compact(
            'profile', 'employer', 'role', 'olderRole', 'project', 'publicFact', 'restrictedFact', 'privateFact', 'olderFact',
            'posting', 'analysis', 'match', 'webhook', 'billing', 'rails', 'react', 'culture',
        );
    }

    public static function fact(CareerProfile $profile, string $key, string $statement, Visibility $visibility, Role|Project $owner): CareerFact
    {
        return CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => $key,
            'fact_type' => CareerFactType::Bullet,
            'statement' => $statement,
            'verification' => Verification::Verified,
            'visibility' => $visibility,
            'attributable_type' => $owner->getMorphClass(),
            'attributable_id' => $owner->getKey(),
        ]);
    }

    /**
     * @param  array<int, array{0: CareerFact, 1: MatchRelationship}>  $facts
     * @param  array<string, mixed>  $extra  Extra JobAnalysisFinding attributes.
     */
    public static function finding(
        JobAnalysis $analysis,
        JobMatch $match,
        JobAnalysisFindingCategory $category,
        string $label,
        string $statement,
        ?JobAnalysisRequirementStrength $strength,
        JobAnalysisEmphasis $emphasis,
        JobMatchCoverage $coverage,
        array $facts,
        array $extra = [],
    ): JobAnalysisFinding {
        $finding = JobAnalysisFinding::factory()->create([
            'job_analysis_id' => $analysis->id,
            'category' => $category,
            'label' => $label,
            'statement' => $statement,
            'requirement_strength' => $strength,
            'emphasis' => $emphasis,
            ...$extra,
        ]);

        $matchFinding = JobMatchFinding::factory()->create([
            'job_match_id' => $match->id,
            'job_analysis_finding_id' => $finding->id,
            'coverage' => $coverage,
        ]);

        foreach ($facts as [$fact, $relationship]) {
            CareerFactMatch::factory()->create([
                'job_match_finding_id' => $matchFinding->id,
                'career_fact_id' => $fact->id,
                'relationship' => $relationship,
            ]);
        }

        return $finding;
    }
}
