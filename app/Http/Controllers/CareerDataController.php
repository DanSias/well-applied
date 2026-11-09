<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Support\CareerData\CareerDataBrowser;
use App\Support\CareerData\MetricFormatter;
use App\Support\CareerData\RoleDateFormatter;
use App\Support\CurrentCareerProfile;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only. This controller only ever reads the canonical career
 * dataset — no create/update/delete action exists here, or anywhere else
 * for career data. The deterministic importer (`career:import`) remains
 * the only way canonical data changes.
 */
class CareerDataController extends Controller
{
    /**
     * A literal SQL fragment (not built from dynamic input) ordering
     * skills into a readable category order rather than alphabetical —
     * matches App\Enums\SkillCategory's own declared order.
     */
    private const SKILL_CATEGORY_ORDER_SQL = <<<'SQL'
        CASE category
            WHEN 'build_technology' THEN 0
            WHEN 'platform_integration' THEN 1
            WHEN 'capability' THEN 2
            WHEN 'practice' THEN 3
            ELSE 4
        END
        SQL;

    public function index(Request $request, CareerDataBrowser $browser): Response
    {
        // Not resolve(): this page has a deliberate, tested empty state
        // for "no profile yet" (a freshly migrated, not-yet-imported
        // database) — see App\Support\CurrentCareerProfile.
        $profile = CurrentCareerProfile::tryResolve();

        if ($profile === null) {
            return Inertia::render('career-data/index', [
                'profile' => null,
                'employers' => [],
                'education' => [],
                'skills' => [],
                'view' => 'experience',
                'counts' => ['facts' => 0, 'confirmed' => 0, 'employers' => 0, 'skills' => 0],
                'facts' => null,
                'filters' => $this->emptyFilters(),
                'pagination' => null,
                'filter_options' => ['employers' => [], 'projects' => []],
            ]);
        }

        $profile->load([
            'directCareerFacts',
            'employers' => fn ($query) => $query->orderBy('sort_order')->withCount('careerFacts'),
            'employers.roles' => fn ($query) => $query->orderBy('sort_order')->withCount('careerFacts'),
            'employers.roles.projects' => fn ($query) => $query->orderBy('sort_order')->withCount('careerFacts'),
            // Project skills are derived (see Project::derivedSkills() /
            // docs/domain-model.md), not stored in project_skill — eager
            // loading each Project's directly-attributed facts and their
            // Skills here is what lets derivedSkills() avoid N+1 below.
            'employers.roles.projects.careerFacts.skills',
            'educations' => fn ($query) => $query->orderBy('sort_order'),
        ]);

        $skills = Skill::query()
            ->where('career_profile_id', $profile->id)
            ->withCount('careerFacts')
            ->orderByRaw(self::SKILL_CATEGORY_ORDER_SQL)
            ->orderBy('name')
            ->get();
        $skillProjects = $browser->skillProjects($profile);
        $highlights = $this->highlights($profile);

        $view = in_array($request->query('view'), ['experience', 'facts', 'skills'], true) ? $request->query('view') : 'experience';
        $filters = $this->filters($request);
        $perPage = in_array((int) $request->query('per_page'), CareerDataBrowser::PER_PAGE_OPTIONS, true) ? (int) $request->query('per_page') : CareerDataBrowser::PER_PAGE_OPTIONS[0];
        $facts = null;
        $pagination = null;

        if ($view === 'facts') {
            $query = $browser->query($profile, $filters);
            $total = (clone $query)->count();
            $last = max(1, (int) ceil($total / $perPage));
            $page = min($last, max(1, (int) $request->query('page', 1)));
            $interview = $browser->interviewFactIds($profile)->flip();
            $facts = $browser->placeFacts($browser->withDetails($query)->forPage($page, $perPage)->get())
                ->map(fn (CareerFact $fact) => $this->transformFactRow($fact, $interview))->values()->all();
            $pagination = [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $last,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1, 'to' => min($total, $page * $perPage),
                'per_page_options' => CareerDataBrowser::PER_PAGE_OPTIONS,
            ];
        }

        $employers = $profile->employers
            ->sortByDesc(fn (Employer $e) => $e->roles->map(fn (Role $r) => ($r->start_year ?? 0) * 100 + ($r->start_month ?? 0))->max() ?? 0)
            ->values();

        return Inertia::render('career-data/index', [
            'profile' => [
                'name' => $profile->name,
                'facts' => $this->transformFactSummaries($profile->directCareerFacts),
            ],
            'employers' => $employers->map(fn (Employer $e) => $this->transformEmployer($e, $highlights))->all(),
            'education' => $profile->educations->map($this->transformEducation(...))->all(),
            'skills' => $skills->map(fn (Skill $s) => $this->transformSkill($s, $skillProjects[$s->id] ?? []))->all(),
            'view' => $view,
            'counts' => [
                'facts' => CareerFact::query()->where('career_profile_id', $profile->id)->count(),
                'confirmed' => $browser->query($profile, [...$this->emptyFilters(), 'provenance' => 'confirmed'])->count(),
                'employers' => $profile->employers->count(),
                'skills' => $skills->count(),
            ],
            'facts' => $facts,
            'filters' => $filters,
            'pagination' => $pagination,
            'filter_options' => $view === 'facts' ? $browser->filterOptions($profile) : ['employers' => [], 'projects' => []],
        ]);
    }

    /**
     * @return array{q: string, employer: ?int, project: ?string, visibility: ?string, provenance: ?string, skill: ?string}
     */
    private function filters(Request $request): array
    {
        $text = fn (string $key) => ($v = trim((string) $request->query($key, ''))) === '' ? null : $v;
        $visibility = $text('visibility');
        $provenance = $text('provenance');

        return [
            'q' => trim((string) $request->query('q', '')),
            'employer' => $text('employer') !== null && ctype_digit($text('employer')) ? (int) $text('employer') : null,
            'project' => $text('project'),
            'visibility' => in_array($visibility, ['public', 'restricted', 'private'], true) ? $visibility : null,
            'provenance' => in_array($provenance, CareerDataBrowser::PROVENANCE, true) ? $provenance : null,
            'skill' => $text('skill'),
        ];
    }

    /**
     * @return array{q: string, employer: null, project: null, visibility: null, provenance: null, skill: null}
     */
    private function emptyFilters(): array
    {
        return ['q' => '', 'employer' => null, 'project' => null, 'visibility' => null, 'provenance' => null, 'skill' => null];
    }

    /**
     * Up to three headline facts per role and project: metric facts first, then the rest in their recorded order.
     *
     * @return array<string, array<int, array<int, array<string, mixed>>>>
     */
    private function highlights(CareerProfile $profile): array
    {
        $facts = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->whereIn('attributable_type', [(new Role)->getMorphClass(), (new Project)->getMorphClass()])
            ->withExists(['evidence as confirmed' => fn ($q) => $q->where('source', 'user_confirmed')])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        $out = ['role' => [], 'project' => []];

        foreach ($facts->sortBy(fn (CareerFact $f) => $f->fact_type->value === 'metric' ? 0 : 1)->groupBy('attributable_type') as $type => $group) {
            $bucket = $type === (new Role)->getMorphClass() ? 'role' : 'project';

            foreach ($group->groupBy('attributable_id') as $id => $list) {
                $out[$bucket][(int) $id] = $list->take(3)->map(fn (CareerFact $f) => [
                    'key' => $f->key,
                    'statement' => $f->statement,
                    'visibility' => $f->visibility->value,
                    'confirmed' => (bool) $f->getAttribute('confirmed'),
                ])->values()->all();
            }
        }

        return $out;
    }

    public function showFact(CareerFact $careerFact, CareerDataBrowser $browser): Response
    {
        $careerFact->load([
            'attributable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Role::class => ['employer'],
                Project::class => ['role.employer'],
            ]),
            'evidence',
            'metric',
            'skills',
        ]);

        $interview = $browser->interviewFactIds($careerFact->careerProfile)->flip();

        return Inertia::render('career-data/fact', [
            'fact' => $this->transformFactDetail($careerFact, $interview),
        ]);
    }

    /**
     * @param  array<string, array<int, array<int, array<string, mixed>>>>  $highlights
     * @return array<string, mixed>
     */
    private function transformEmployer(Employer $employer, array $highlights): array
    {
        return [
            'id' => $employer->id,
            'name' => $employer->name,
            'short_name' => $employer->short_name,
            'description' => $employer->description,
            'fact_count' => $employer->career_facts_count,
            'roles' => $employer->roles->sortByDesc(fn (Role $r) => ($r->start_year ?? 0) * 100 + ($r->start_month ?? 0))->values()->map(fn (Role $r) => $this->transformRole($r, $highlights))->all(),
        ];
    }

    /**
     * @param  array<string, array<int, array<int, array<string, mixed>>>>  $highlights
     * @return array<string, mixed>
     */
    private function transformRole(Role $role, array $highlights): array
    {
        $dates = RoleDateFormatter::format($role);

        return [
            'id' => $role->id,
            'title' => $role->title,
            'date_label' => $dates['label'],
            'is_current' => $dates['is_current'],
            'fact_count' => $role->career_facts_count,
            'highlights' => $highlights['role'][$role->id] ?? [],
            'projects' => $role->projects->map(fn (Project $p) => $this->transformProject($p, $highlights))->all(),
        ];
    }

    /**
     * @param  array<string, array<int, array<int, array<string, mixed>>>>  $highlights
     * @return array<string, mixed>
     */
    private function transformProject(Project $project, array $highlights): array
    {
        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'description' => $project->description,
            'visibility' => $project->default_visibility?->value,
            'fact_count' => $project->career_facts_count,
            'highlights' => $highlights['project'][$project->id] ?? [],
            'skills' => $project->derivedSkills()->map($this->transformSkillRef(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEducation(Education $education): array
    {
        return [
            'id' => $education->id,
            'institution' => $education->institution,
            'degree' => $education->degree,
            'field_of_study' => $education->field_of_study,
            'start_year' => $education->start_year,
            'end_year' => $education->end_year,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     * @return array<string, mixed>
     */
    private function transformSkill(Skill $skill, array $projects): array
    {
        return [
            'slug' => $skill->slug,
            'name' => $skill->name,
            'category' => $skill->category->value,
            'career_fact_count' => $skill->career_facts_count,
            // Derived from approved project facts that carry the skill, never from the unused project_skill pivot.
            'project_count' => count($projects),
            'projects' => $projects,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function transformSkillRef(Skill $skill): array
    {
        return [
            'slug' => $skill->slug,
            'name' => $skill->name,
            'category' => $skill->category->value,
        ];
    }

    /**
     * @param  Collection<int, CareerFact>  $facts
     * @return array<int, array<string, mixed>>
     */
    private function transformFactSummaries(Collection $facts): array
    {
        return $facts->map($this->transformFactSummary(...))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFactSummary(CareerFact $fact): array
    {
        return [
            'key' => $fact->key,
            'fact_type' => $fact->fact_type->value,
            'statement' => $fact->statement,
            'verification' => $fact->verification->value,
            'visibility' => $fact->visibility->value,
            'has_metric' => $fact->metric()->exists(),
        ];
    }

    /**
     * @param  Collection<int, int>  $interviewIds
     * @return array<string, mixed>
     */
    private function transformFactDetail(CareerFact $fact, Collection $interviewIds): array
    {
        return [
            'key' => $fact->key,
            'fact_type' => $fact->fact_type->value,
            'statement' => $fact->statement,
            'verification' => $fact->verification->value,
            'visibility' => $fact->visibility->value,
            'notes' => $fact->notes,
            'attribution' => $this->transformAttribution($fact),
            'metric' => $fact->metric ? $this->transformMetric($fact->metric) : null,
            'skills' => $fact->skills->map($this->transformSkillRef(...))->all(),
            'evidence' => $fact->evidence->map($this->transformEvidence(...))->all(),
            ...$this->provenance($fact, $interviewIds),
        ];
    }

    /**
     * Where a fact came from, in the terms the UI shows: the evidence sources it has, whether and when you confirmed it
     * yourself, and whether it was added by approving an interview answer.
     *
     * @param  Collection<int, int>  $interviewIds  keyed by fact id
     * @return array<string, mixed>
     */
    private function provenance(CareerFact $fact, Collection $interviewIds): array
    {
        $confirmed = $fact->evidence->filter(fn (Evidence $e) => $e->source === EvidenceSource::UserConfirmed);

        return [
            'sources' => $fact->evidence->map(fn (Evidence $e) => $e->source->value)->unique()->values()->all(),
            'confirmed' => $confirmed->isNotEmpty(),
            'confirmed_at' => $confirmed->map(fn (Evidence $e) => $e->confirmed_at)->filter()->max()?->toDateString(),
            'via_interview' => $interviewIds->has($fact->id),
            'project_slug' => $fact->attributable instanceof Project ? $fact->attributable->slug : null,
        ];
    }

    /**
     * One row of the Career Facts list.
     *
     * @param  Collection<int, int>  $interviewIds  keyed by fact id
     * @return array<string, mixed>
     */
    private function transformFactRow(CareerFact $fact, Collection $interviewIds): array
    {
        return [
            ...$this->transformFactSummary($fact),
            'has_metric' => (bool) $fact->getAttribute('metric_exists'),
            'notes' => $fact->notes,
            'place' => $this->transformAttribution($fact)['path'],
            'skills' => $fact->skills->map($this->transformSkillRef(...))->all(),
            ...$this->provenance($fact, $interviewIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAttribution(CareerFact $fact): array
    {
        $attributable = $fact->attributable;

        return match (true) {
            $attributable instanceof CareerProfile => [
                'type' => 'career_profile',
                'path' => ['Career-wide'],
            ],
            $attributable instanceof Employer => [
                'type' => 'employer',
                'path' => [$attributable->name],
            ],
            $attributable instanceof Role => [
                'type' => 'role',
                'path' => [$attributable->employer->name, $attributable->title],
            ],
            $attributable instanceof Project => [
                'type' => 'project',
                'path' => [
                    $attributable->role->employer->name,
                    $attributable->role->title,
                    $attributable->name,
                ],
            ],
            default => ['type' => 'unknown', 'path' => []],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMetric(Metric $metric): array
    {
        return [
            'display' => MetricFormatter::format($metric),
            'value' => (float) $metric->value,
            'value_max' => $metric->value_max !== null ? (float) $metric->value_max : null,
            'is_range' => $metric->isRange(),
            'unit' => $metric->unit,
            'comparator' => $metric->comparator?->value,
            'scope_note' => $metric->scope_note,
            'guardrail' => $metric->guardrail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEvidence(Evidence $evidence): array
    {
        return [
            'source' => $evidence->source->value,
            'document' => $evidence->document,
            'path' => $evidence->path,
            'section' => $evidence->section,
            'locator' => $evidence->locator,
            'quoted_text' => $evidence->quoted_text,
            'confirmed_at' => $evidence->confirmed_at?->toDateString(),
            'note' => $evidence->note,
        ];
    }
}
