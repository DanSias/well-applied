<?php

namespace App\Support\ResumeVariant;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * Which career facts may be evidence for an Experience bullet under a role: facts attributed to the role, to one of its
 * projects, or to its employer. Whole-career (profile-level) facts never are.
 */
final class RoleEligibleFacts
{
    /**
     * @param  Collection<int, Role>  $roles  Already loaded with their own `projects`.
     * @return array<int, array<string, true>> role_id => set of eligible CareerFact keys.
     */
    public function forRoles(Collection $roles, CareerProfile $profile): array
    {
        $eligibleFactKeysForRole = [];
        $roleIdByProjectId = [];
        $roleIdsByEmployerId = [];

        foreach ($roles as $role) {
            $eligibleFactKeysForRole[$role->id] = [];
            $roleIdsByEmployerId[$role->employer_id][] = $role->id;

            foreach ($role->projects as $project) {
                $roleIdByProjectId[$project->id] = $role->id;
            }
        }

        $facts = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->get(['key', 'attributable_type', 'attributable_id']);

        foreach ($facts as $fact) {
            $roleIds = match ($fact->attributable_type) {
                'role' => [$fact->attributable_id],
                'project' => isset($roleIdByProjectId[$fact->attributable_id]) ? [$roleIdByProjectId[$fact->attributable_id]] : [],
                'employer' => $roleIdsByEmployerId[$fact->attributable_id] ?? [],
                default => [],
            };

            foreach ($roleIds as $roleId) {
                if (array_key_exists($roleId, $eligibleFactKeysForRole)) {
                    $eligibleFactKeysForRole[$roleId][$fact->key] = true;
                }
            }
        }

        return $eligibleFactKeysForRole;
    }
}
