<?php

namespace App\Support\CareerData;

use App\Enums\EvidenceSource;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\EvidenceProposal;
use App\Models\Project;
use App\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries behind the Career Data views: a filtered, paginated list of the approved facts, where each fact came
 * from, and which projects a skill is evidenced on.
 *
 * Nothing here writes. A skill's projects are DERIVED from the approved facts attributed to a project that carry that
 * skill; the `project_skill` pivot is intentionally unused (see docs/domain-model.md "Project skills are derived, not
 * stored") and is never read here.
 */
final class CareerDataBrowser
{
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const PROVENANCE = ['confirmed', 'interview', 'resume', 'portfolio_repository'];

    /**
     * @param  array{q: string, employer: ?int, project: ?string, visibility: ?string, provenance: ?string, skill: ?string}  $filters
     * @return Builder<CareerFact>
     */
    public function query(CareerProfile $profile, array $filters): Builder
    {
        $query = CareerFact::query()->where('career_profile_id', $profile->id);

        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn (Builder $q) => $q->where('statement', 'like', $like)->orWhere('notes', 'like', $like));
        }

        if ($filters['visibility'] !== null) {
            $query->where('visibility', $filters['visibility']);
        }

        if ($filters['project'] !== null) {
            $projectId = Project::query()->where('career_profile_id', $profile->id)->where('slug', $filters['project'])->value('id');
            $query->where('attributable_type', (new Project)->getMorphClass())->where('attributable_id', $projectId ?? 0);
        } elseif ($filters['employer'] !== null) {
            $roleIds = Role::query()->where('employer_id', $filters['employer'])->pluck('id');
            $projectIds = Project::query()->whereIn('role_id', $roleIds)->pluck('id');
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $r) => $r->where('attributable_type', (new Role)->getMorphClass())->whereIn('attributable_id', $roleIds))
                ->orWhere(fn (Builder $r) => $r->where('attributable_type', (new Project)->getMorphClass())->whereIn('attributable_id', $projectIds)));
        }

        if ($filters['skill'] !== null) {
            $query->whereHas('skills', fn (Builder $q) => $q->where('slug', $filters['skill']));
        }

        match ($filters['provenance']) {
            'confirmed' => $query->whereHas('evidence', fn (Builder $q) => $q->where('source', EvidenceSource::UserConfirmed->value)),
            'resume' => $query->whereHas('evidence', fn (Builder $q) => $q->where('source', EvidenceSource::Resume->value)),
            'portfolio_repository' => $query->whereHas('evidence', fn (Builder $q) => $q->whereIn('source', [EvidenceSource::Portfolio->value, EvidenceSource::Repository->value])),
            'interview' => $query->whereIn('id', $this->interviewFactIds($profile)),
            default => null,
        };

        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Facts that exist because an interview or clarification answer was approved.
     *
     * @return Collection<int, int>
     */
    public function interviewFactIds(CareerProfile $profile): Collection
    {
        return EvidenceProposal::query()
            ->where('career_profile_id', $profile->id)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->whereNotNull('career_fact_id')
            ->pluck('career_fact_id');
    }

    /**
     * Eager loads for the list rows: evidence (where each fact came from), skills and whether a metric exists. Where each
     * fact sits is loaded onto the fetched page by placeFacts().
     *
     * @param  Builder<CareerFact>  $query
     * @return Builder<CareerFact>
     */
    public function withDetails(Builder $query): Builder
    {
        return $query->with('evidence')->with('skills')->withExists('metric');
    }

    /**
     * Loads the employer/role/project each fact is attributed to.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, CareerFact>  $facts
     * @return \Illuminate\Database\Eloquent\Collection<int, CareerFact>
     */
    public function placeFacts(\Illuminate\Database\Eloquent\Collection $facts): \Illuminate\Database\Eloquent\Collection
    {
        return $facts->loadMorph('attributable', [
            Role::class => ['employer'],
            Project::class => ['role.employer'],
        ]);
    }

    /**
     * The employer, project and total counts the filter controls offer. Only places that hold facts are offered.
     *
     * @return array{employers: array<int, array{id: int, name: string}>, projects: array<int, array{slug: string, name: string, employer_id: int|null}>}
     */
    public function filterOptions(CareerProfile $profile): array
    {
        $employers = Employer::query()->where('career_profile_id', $profile->id)->orderBy('name')->get(['id', 'name']);
        $projects = Project::query()->where('career_profile_id', $profile->id)->with('role:id,employer_id')->orderBy('name')->get();

        return [
            'employers' => $employers->map(fn (Employer $e) => ['id' => $e->id, 'name' => $e->name])->values()->all(),
            'projects' => $projects->map(fn (Project $p) => ['slug' => $p->slug, 'name' => $p->name, 'employer_id' => $p->role?->employer_id])->values()->all(),
        ];
    }

    /**
     * Which projects each skill is evidenced on, derived from approved project-attributed facts.
     *
     * @return array<int, list<array{slug: string, name: string, employer: string|null, fact_count: int}>> keyed by skill id
     */
    public function skillProjects(CareerProfile $profile): array
    {
        $rows = DB::table('career_fact_skill as cfs')
            ->join('career_facts as cf', 'cf.id', '=', 'cfs.career_fact_id')
            ->where('cf.career_profile_id', $profile->id)
            ->where('cf.attributable_type', (new Project)->getMorphClass())
            ->groupBy('cfs.skill_id', 'cf.attributable_id')
            ->get(['cfs.skill_id', 'cf.attributable_id as project_id', DB::raw('count(*) as fact_count')]);

        $projects = Project::query()->whereIn('id', $rows->pluck('project_id')->unique())->with('role.employer')->get()->keyBy('id');
        $bySkill = [];

        foreach ($rows as $row) {
            $project = $projects->get($row->project_id);

            if ($project === null) {
                continue;
            }

            $bySkill[(int) $row->skill_id][] = [
                'slug' => $project->slug,
                'name' => $project->name,
                'employer' => $project->role?->employer?->name,
                'fact_count' => (int) $row->fact_count,
            ];
        }

        foreach ($bySkill as &$list) {
            usort($list, fn (array $a, array $b) => [$b['fact_count'], $a['name']] <=> [$a['fact_count'], $b['name']]);
        }

        return $bySkill;
    }
}
