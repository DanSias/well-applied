<?php

namespace Tests\Support;

use App\Contracts\GeneratesJobQualification;
use App\Enums\CareerFactType;
use App\Enums\DescriptionCompleteness;
use App\Enums\JobRemoteStatus;
use App\Enums\SkillCategory;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\Skill;

/**
 * A candidate and a set of representative postings for the qualification policy tests. The descriptions are written so that
 * S001, S002 and S003 are always real segments, which the scripted model responses cite.
 */
final class QualificationFixtures
{
    /** The skills the fixture candidate has substantiated. */
    public const SKILLS = ['PHP' => 'build_technology', 'Laravel' => 'build_technology', 'TypeScript' => 'build_technology', 'React' => 'build_technology', 'Node.js' => 'build_technology', 'Vue' => 'build_technology', 'MySQL' => 'platform_integration'];

    public static function candidate(): CareerProfile
    {
        $career = CareerProfile::factory()->create();
        $role = Role::factory()->create([
            'employer_id' => Employer::factory()->create(['career_profile_id' => $career->id, 'name' => 'RocketGate'])->id,
            'title' => 'Senior Software Engineer', 'start_year' => 2019, 'end_year' => null,
        ]);

        foreach (self::SKILLS as $name => $category) {
            $skill = Skill::factory()->create(['career_profile_id' => $career->id, 'name' => $name, 'slug' => strtolower($name), 'category' => SkillCategory::from($category)]);
            $fact = CareerFact::factory()->create([
                'career_profile_id' => $career->id, 'statement' => "Delivered production features with {$name}.", 'fact_type' => CareerFactType::Bullet,
                'verification' => Verification::Verified, 'visibility' => Visibility::Public, 'attributable_type' => 'role', 'attributable_id' => $role->id,
            ]);
            $fact->skills()->sync([$skill->id]);
        }

        return $career;
    }

    /** @param array<string, mixed> $attributes */
    public static function job(CareerProfile $career, string $description, array $attributes = []): JobPosting
    {
        return JobPosting::factory()->create(array_merge([
            'career_profile_id' => $career->id,
            'title' => 'Senior Software Engineer', 'company' => 'Acme',
            'location' => 'Remote, United States', 'remote_status' => JobRemoteStatus::Remote,
            'description_completeness' => DescriptionCompleteness::Complete,
            'description' => $description,
        ], $attributes));
    }

    /**
     * A scripted model answer. Defaults describe a strong, hands-on Laravel role; override what a scenario changes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function answer(array $overrides = []): array
    {
        return $overrides + [
            'verdict' => 'strong',
            'role_type' => 'full_stack',
            'primary_role_focus' => 'Build and own product features end to end.',
            'hands_on' => 'hands_on',
            'core_responsibilities' => ['Ship product features', 'Design APIs'],
            'technologies' => [self::tech('Laravel', 'mandatory', 'familiar')],
            'seniority' => 'senior',
            'fit_reasons' => [['text' => 'Hands-on product engineering on a stack the candidate has.', 'evidence_refs' => ['S001']]],
            'biggest_concern' => null,
            'biggest_concern_evidence_refs' => [],
            'management_evidence_refs' => [],
            'practical_concerns' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function tech(string $name, string $requirement, string $familiarity, string $depth = 'working', bool $transferable = false, string $ref = 'S002', bool $central = true): array
    {
        return ['name' => $name, 'requirement' => $requirement, 'familiarity' => $familiarity, 'depth' => $depth, 'central' => $central, 'transferable_accepted' => $transferable, 'evidence_refs' => [$ref]];
    }

    public static function fake(): FakeJobQualificationProvider
    {
        $fake = new FakeJobQualificationProvider;
        app()->instance(GeneratesJobQualification::class, $fake);

        return $fake;
    }

    private const FILLER = 'The team ships weekly, reviews code together and values clear written communication. You will work with product managers and designers to turn ideas into reliable software that customers use every day.';

    public static function laravel(): string
    {
        return 'We are hiring a Senior Software Engineer to build our customer platform. You will build features across a Laravel and PHP backend and a Vue interface. You will design REST APIs and integrations with payment partners. You will work with MySQL and write tests for everything you ship. Experience with TypeScript is helpful. '.self::FILLER;
    }

    public static function react(): string
    {
        return 'We need a Senior Frontend Engineer to own our React and TypeScript web application. You will build accessible components and a design system. You will integrate with our REST APIs and improve performance. Strong knowledge of modern JavaScript is required. '.self::FILLER;
    }

    public static function infrastructure(): string
    {
        return 'Senior Platform Engineer to run our cloud infrastructure. You will manage Kubernetes clusters, Terraform modules and CI pipelines on AWS. You will carry the on-call rotation and own incident response. Some scripting in TypeScript and Python is used for tooling. You will rarely touch application code. '.self::FILLER;
    }

    public static function railsDeep(): string
    {
        return 'We are looking for a Senior Backend Engineer on our Ruby on Rails monolith. You must have 5+ years of production Ruby on Rails experience. You will design Rails services and tune ActiveRecord queries. You will mentor engineers on Rails conventions. '.self::FILLER;
    }

    public static function railsEquivalent(): string
    {
        return 'We are looking for a Senior Backend Engineer on our Ruby on Rails monolith. Experience with Rails or an equivalent framework such as Laravel or Django is welcome, and we will teach you the rest. You will design services and tune database queries. You will mentor engineers on good practice. '.self::FILLER;
    }

    public static function pythonPreferred(): string
    {
        return 'We are hiring a Senior Software Engineer for our Laravel and PHP platform. You will build APIs, queue workers and admin tools. You will work with MySQL daily. Python is a plus for our data scripts but not required. '.self::FILLER;
    }

    public static function ambiguousLocation(): string
    {
        return 'We are hiring a Senior Software Engineer to build our Laravel and PHP platform. You will design APIs and work with MySQL. Our team is distributed and meets in person from time to time. You will ship features with product and design partners. '.self::FILLER;
    }
}
