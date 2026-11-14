<?php

namespace App\Support\JobQualification;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Role;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\ResumeVariant\ResumeEligibility;

/**
 * A compact view of what the career profile can substantiate, built from the same eligible CareerFacts and Skills the full
 * job match uses (never Private ones) rather than from a second store. It lists skills with how much evidence stands behind
 * each and when they were last used, plus the roles held. It deliberately carries no statements: the screen only needs to
 * know what the person has demonstrably worked with, and a skill that is absent here is "not evidenced", never "lacking".
 */
final class CandidateCapabilitySummary
{
    /**
     * @return array{skills: list<array<string, mixed>>, roles: list<array<string, mixed>>, clearance_evidence: bool}
     */
    public function build(CareerProfile $profile): array
    {
        $facts = ResumeEligibility::eligibleCareerFactsQuery($profile)
            ->with(['skills', 'attributable'])
            ->orderBy('id')
            ->get();

        $skills = [];
        $clearance = false;

        foreach ($facts as $fact) {
            /** @var CareerFact $fact */
            $clearance = $clearance || preg_match('/\bclearance\b/i', $fact->statement) === 1;
            $attribution = CareerFactAttribution::resolve($fact);
            $lastUsed = $attribution->roleStartYear === null ? null : ($attribution->roleEndYear ?? 'current');

            foreach ($fact->skills as $skill) {
                $entry = $skills[$skill->id] ?? ['name' => $skill->name, 'category' => $skill->category->value, 'evidence_count' => 0, 'last_used' => null];
                $entry['evidence_count']++;
                $entry['last_used'] = self::later($entry['last_used'], $lastUsed);
                $skills[$skill->id] = $entry;
            }
        }

        usort($skills, fn (array $a, array $b) => [$b['evidence_count'], $a['name']] <=> [$a['evidence_count'], $b['name']]);

        $roles = array_values(Role::query()
            ->whereHas('employer', fn ($query) => $query->where('career_profile_id', $profile->id))
            ->with('employer')
            ->orderByDesc('start_year')
            ->get()
            ->map(fn (Role $role) => [
                'title' => $role->title,
                'employer' => $role->employer->name,
                'from' => $role->start_year,
                'to' => $role->end_year ?? 'current',
            ])->all());

        return [
            'skills' => array_slice($skills, 0, QualificationPolicy::MAX_CAPABILITY_SKILLS),
            'roles' => $roles,
            'clearance_evidence' => $clearance,
        ];
    }

    /**
     * @param  array{skills: list<array<string, mixed>>, roles: list<array<string, mixed>>, clearance_evidence: bool}  $summary
     */
    public static function fingerprint(array $summary): string
    {
        return hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    private static function later(int|string|null $a, int|string|null $b): int|string|null
    {
        if ($a === 'current' || $b === 'current') {
            return 'current';
        }

        return max($a ?? 0, $b ?? 0) ?: null;
    }
}
