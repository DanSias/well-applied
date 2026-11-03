<?php

namespace App\Support\ProfileSynthesis;

use App\Enums\Verification;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\Project;
use App\Models\ResumeProfile;
use App\Models\Role;
use App\Support\Expansion\SharedClaims;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeVariant\ResumeEligibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * The evidence inventory of a profile: everything the synthesis may say, each with the fact keys
 * that support it. Deterministic; no model call. It never contains Private evidence (only a count
 * of what was left out) and never writes presentation language; that is the separate, editable
 * half of a ProfileSynthesis.
 *
 * Projects are ranked on engineering signals, not on how many facts they have: ownership,
 * architecture, technical complexity (distinct technologies and integrations), relevance to what
 * the market sample asks for, distinctiveness within this career, and demonstrated outcomes. Each
 * signal is 0 to 1 and kept in the result so the ranking can be read and questioned.
 */
final class ProfileAssembly
{
    public const VERSION = 'profile-inventory-v1';

    private const OWNERSHIP = '/\b(?:independently|owned|owning|from scratch|from the ground up|designed and (?:built|implemented|developed)|architected|end[- ]to[- ]end)\b/i';

    private const DESIGN = '/\b(?:deterministic|idempotent|resumable|encrypted|evidence[- ]linked|normaliz\w+|provider[- ]abstracted|pipeline|dry[- ]run|audit\w*|throttl\w+|local[- ]first|single (?:API layer|source of truth)|reproducible)\b/i';

    private const OUTCOME = '/\b(?:reduc\w+|saved|increas\w+|improv\w+|grew|growth|eliminat\w+)\b/i';

    private const FIRST_PERSON = "/(?<![\\w'])(?:I|I've|I'm|my|me)(?![\\w])/";

    /** Weights of the six project signals; they sum to 1. */
    private const WEIGHTS = ['ownership' => 0.20, 'architecture' => 0.20, 'complexity' => 0.15, 'relevance' => 0.25, 'distinctiveness' => 0.10, 'outcomes' => 0.10];

    public function __construct(private readonly CapabilityCoverage $coverage) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(ResumeProfile $profile): array
    {
        $career = $profile->careerProfile;
        $facts = ResumeEligibility::eligibleCareerFactsQuery($career)->with([
            'skills', 'metric',
            'attributable' => function (Relation $relation) {
                /** @var MorphTo<Model, CareerFact> $relation */
                $relation->morphWith([Role::class => ['employer'], Project::class => ['role.employer']]);
            },
        ])->orderBy('sort_order')->orderBy('id')->get();

        $excluded = [];
        $usable = $facts->filter(function (CareerFact $fact) use (&$excluded) {
            if (preg_match(self::FIRST_PERSON, $fact->statement) === 1) {
                $excluded[] = ['fact_key' => $fact->key, 'reason' => 'First-person wording; it can support a capability but is not resume-ready.'];

                return false;
            }

            return true;
        });

        $coverage = $this->coverage->compute($career);
        $aggregate = $this->aggregate($profile);
        $weight = $this->marketWeights($aggregate);
        $directKeys = array_keys(array_filter($coverage['capabilities'], fn (array $c) => $c['status'] === CapabilityCoverage::DIRECT));

        // Every usable fact is indexed and scored, shared-claim siblings included: a claim attached to three
        // projects is real evidence for each of them. Only the accomplishments list counts it once.
        $index = [];
        $signals = [];

        foreach ($usable as $fact) {
            $caps = array_values(array_intersect($this->coverage->capabilitiesIn($fact->statement), $directKeys));
            $index[$fact->key] = $this->factEntry($fact, [], $caps);
            $signals[$fact->key] = $this->factSignals($fact, $caps, $weight);
        }

        $projects = $this->projects($usable, $index, $signals, $weight);
        $representatives = $this->representatives($usable, $projects);
        [, $alsoAttached] = SharedClaims::collapse($representatives);

        foreach ($representatives as $fact) {
            $index[$fact->key]['shared_with'] = $alsoAttached[$fact->id] ?? [];
        }

        $accomplishments = $this->accomplishments($representatives, $index, $signals);

        return [
            'version' => self::VERSION,
            'target' => ['name' => $profile->name, 'slug' => $profile->slug],
            'provenance' => $this->provenance($profile, $aggregate, $coverage['evidence_revision']),
            'market_priorities' => $this->marketPriorities($aggregate, $coverage['capabilities']),
            'capabilities' => $this->capabilities($coverage['capabilities'], $aggregate, $index, $signals),
            'limitations' => CareerLimitation::query()->where('career_profile_id', $career->id)->active()->orderBy('topic')->pluck('topic')->all(),
            'projects' => $projects,
            'accomplishments' => $accomplishments,
            'roles' => $this->roles($career->id, $accomplishments, $projects),
            'facts' => $index,
            'excluded' => $excluded,
            'private_omitted' => CareerFact::query()->where('career_profile_id', $career->id)->where('visibility', 'private')->count(),
        ];
    }

    /**
     * One fact per shared claim, attached to the place whose project ranks highest, so the claim is offered
     * (and later placed on the resume) under its strongest valid attribution.
     *
     * @param  Collection<int, CareerFact>  $facts
     * @param  array<int, array<string, mixed>>  $projects
     * @return Collection<int, CareerFact>
     */
    private function representatives(Collection $facts, array $projects): Collection
    {
        $score = collect($projects)->mapWithKeys(fn (array $p) => [$p['project_id'] => $p['score']])->all();
        $best = [];

        foreach ($facts->whereNotNull('claim_group') as $fact) {
            $own = $fact->attributable_type === 'project' ? ($score[$fact->attributable_id] ?? 0.0) : 0.0;

            if (! isset($best[$fact->claim_group]) || $own > $best[$fact->claim_group]['score']) {
                $best[$fact->claim_group] = ['score' => $own, 'id' => $fact->id];
            }
        }

        return $facts->filter(fn (CareerFact $f) => $f->claim_group === null || $best[$f->claim_group]['id'] === $f->id)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregate(ResumeProfile $profile): array
    {
        $sample = $profile->currentSample;

        return $sample === null || $sample->aggregate === null ? [] : (array) $sample->aggregate;
    }

    /**
     * How much the market asks for each capability: share of postings, boosted by how often it is required.
     *
     * @param  array<string, mixed>  $aggregate
     * @return array<string, float>
     */
    private function marketWeights(array $aggregate): array
    {
        $total = max(1, (int) ($aggregate['postings'] ?? 0));
        $weights = [];

        foreach ((array) ($aggregate['capabilities'] ?? []) as $key => $c) {
            $postings = (int) $c['postings'];
            $weights[(string) $key] = ($postings / $total) * (1 + 0.5 * ($postings === 0 ? 0 : (int) $c['required'] / $postings));
        }

        return $weights;
    }

    /**
     * @param  array<int, string>  $alsoAttached
     * @param  array<int, string>  $caps
     * @return array<string, mixed>
     */
    private function factEntry(CareerFact $fact, array $alsoAttached, array $caps): array
    {
        $a = CareerFactAttribution::resolve($fact);
        $attributable = $fact->attributable;
        $personal = $fact->attributable_type === 'career_profile' || ($attributable instanceof Project && $attributable->role_id === null);

        return [
            'statement' => $fact->statement, 'visibility' => $fact->visibility->value, 'verification' => $fact->verification->value, 'type' => $fact->fact_type->value,
            'scope' => $fact->attributable_type, 'employer' => $a->employer, 'role' => $a->role, 'project' => $a->project,
            'role_id' => match (true) {
                $attributable instanceof Role => $attributable->id,
                $attributable instanceof Project => $attributable->role_id,
                default => null,
            },
            'project_id' => $attributable instanceof Project ? $attributable->id : null,
            // Whole-career and independent-project evidence is never employment history.
            'personal' => $personal, 'whole_career' => $fact->attributable_type === 'career_profile',
            'shared_with' => $alsoAttached, 'capabilities' => $caps,
            'skills' => $fact->skills->pluck('name')->all(),
            'metric' => $fact->metric === null ? null : ['value' => (float) $fact->metric->value, 'unit' => $fact->metric->unit],
        ];
    }

    /**
     * @param  array<int, string>  $caps
     * @param  array<string, float>  $weight
     * @return array<string, float>
     */
    private function factSignals(CareerFact $fact, array $caps, array $weight): array
    {
        $text = $fact->statement;
        $metric = $fact->metric !== null || preg_match('/\d/', $text) === 1;

        return [
            // Explicit ownership is full credit; having built or designed the thing is most of it.
            'ownership' => preg_match(self::OWNERSHIP, $text) === 1 ? 1.0 : (preg_match('/^(?:Built|Designed|Implemented|Developed|Created|Architected|Integrated)\b/i', $text) === 1 ? 0.6 : 0.0),
            'architecture' => min(1.0, (count(array_filter($caps, fn (string $k) => in_array(config("resume_profiles.capabilities.{$k}.category"), ['architecture', 'practice'], true))) + 0.5 * preg_match_all(self::DESIGN, $text)) / 3),
            'relevance' => array_sum(array_map(fn (string $k) => $weight[$k] ?? 0.0, $caps)),
            'outcome' => $metric && preg_match(self::OUTCOME, $text.' '.($fact->metric->scope_note ?? '')) === 1 ? ($fact->verification === Verification::Verified ? 1.0 : 0.7) : ($metric ? 0.4 : 0.0),
        ];
    }

    /**
     * @param  Collection<int, CareerFact>  $facts
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, array<string, float>>  $signals
     * @param  array<string, float>  $weight
     * @return array<int, array<string, mixed>>
     */
    private function projects(Collection $facts, array $index, array $signals, array $weight): array
    {
        $byProject = $facts->filter(fn (CareerFact $f) => $f->attributable_type === 'project')->groupBy('attributable_id');
        $claimed = $byProject->map(fn (Collection $group) => $this->union($group, fn (CareerFact $f) => $index[$f->key]['capabilities']));
        // The stack a project's facts are tagged with: shown and used for breadth, never promoted to a claim.
        $stack = $byProject->map(fn (Collection $group) => $this->union(
            $group,
            fn (CareerFact $f) => $f->skills->isEmpty() ? [] : $this->coverage->capabilitiesIn($f->skills->pluck('name')->implode(', '))
        ));
        $projectCount = max(1, $byProject->count());
        $raw = [];

        foreach ($byProject as $projectId => $group) {
            /** @var Collection<int, CareerFact> $group */
            $caps = $claimed[$projectId];
            $surface = $this->union($group, fn (CareerFact $f) => [...$index[$f->key]['capabilities'], ...($f->skills->isEmpty() ? [] : $this->coverage->capabilitiesIn($f->skills->pluck('name')->implode(', ')))]);
            $skills = $this->union($group, fn (CareerFact $f) => $f->skills->pluck('name')->all());
            $technologies = count(array_filter($surface, fn (string $k) => config("resume_profiles.capabilities.{$k}.category") === 'technology'));
            $others = $claimed->except($projectId);
            $rarity = $caps === [] ? 0.0 : array_sum(array_map(fn (string $k) => 1 - ($others->filter(fn (array $c) => in_array($k, $c, true))->count() / max(1, $projectCount - 1)), $caps)) / count($caps);

            $raw[$projectId] = [
                'ownership' => min(1.0, max([0.0, ...array_map(fn (CareerFact $f) => $signals[$f->key]['ownership'], $group->all())]) * 0.7 + 0.3 * min(1, $group->filter(fn (CareerFact $f) => $signals[$f->key]['ownership'] > 0)->count() / 2)),
                'architecture' => min(1.0, array_sum(array_map(fn (CareerFact $f) => $signals[$f->key]['architecture'], $group->all())) / 2),
                // Distinct technologies and integrations, never a count of facts.
                'complexity' => min(1.0, (count($skills) / 10 + ($technologies + count(array_intersect($surface, ['api_integration', 'data_pipelines', 'webhooks', 'ai_llm', 'event_driven', 'retry_idempotency']))) / 8) / 2),
                // What the market asks for, blended with how much of the full stack the project spans.
                'relevance' => 0.6 * array_sum(array_map(fn (string $k) => $weight[$k] ?? 0.0, $surface)) + 0.4 * $this->breadth($surface),
                'distinctiveness' => $rarity * min(1.0, count($caps) / 3),
                'outcomes' => min(1.0, array_sum(array_map(fn (CareerFact $f) => $signals[$f->key]['outcome'], $group->all())) / 1.5),
            ];
        }

        $maxRelevance = max(1e-9, max(array_column($raw, 'relevance') ?: [0]));
        $projects = [];

        foreach ($raw as $projectId => $s) {
            $s['relevance'] = $s['relevance'] / $maxRelevance;
            $score = array_sum(array_map(fn (string $k) => self::WEIGHTS[$k] * $s[$k], array_keys(self::WEIGHTS)));
            $first = $index[$byProject[$projectId]->first()->key];

            $projects[] = [
                'id' => "project:{$projectId}", 'project_id' => (int) $projectId, 'name' => $first['project'], 'role_id' => $first['role_id'], 'role' => $first['role'], 'employer' => $first['employer'],
                'personal' => $first['role_id'] === null, 'score' => round($score, 3),
                'signals' => array_map(fn (float $v) => round($v, 2), $s),
                'fact_keys' => $byProject[$projectId]->sortByDesc(fn (CareerFact $f) => $signals[$f->key]['ownership'] + $signals[$f->key]['architecture'] + $signals[$f->key]['outcome'] + $signals[$f->key]['relevance'])->pluck('key')->values()->all(),
                'metric_fact_keys' => $byProject[$projectId]->filter(fn (CareerFact $f) => $signals[$f->key]['outcome'] >= 0.7)->pluck('key')->values()->all(),
                'capabilities' => $caps, 'stack' => $stack[$projectId],
                'reason' => $this->reason($s),
            ];
        }

        // Employment projects first: independent projects never outrank work done for an employer.
        usort($projects, fn (array $a, array $b) => [$a['personal'], $b['score']] <=> [$b['personal'], $a['score']]);

        return array_map(fn (array $p, int $i) => [...$p, 'rank' => $i + 1], $projects, array_keys($projects));
    }

    /**
     * The distinct strings a closure yields across a group of facts, in first-seen order.
     *
     * @param  Collection<int, CareerFact>  $group
     * @param  \Closure(CareerFact): array<int, string>  $values
     * @return array<int, string>
     */
    private function union(Collection $group, \Closure $values): array
    {
        $seen = [];

        foreach ($group as $fact) {
            foreach ($values($fact) as $value) {
                $seen[$value] = true;
            }
        }

        return array_map('strval', array_keys($seen));
    }

    /**
     * How much of the stack a project spans: front end, back end and integration, data, platform, AI. 0 to 1.
     *
     * @param  array<int, string>  $capabilities
     */
    private function breadth(array $capabilities): float
    {
        $layers = [
            ['react', 'vue_angular', 'frontend_architecture', 'javascript_core'],
            ['nodejs', 'php_laravel', 'python_backend', 'rest_api_design', 'api_integration', 'webhooks'],
            ['sql_databases', 'nosql_cache', 'data_pipelines', 'data_modeling'],
            ['cloud_platforms', 'docker', 'ci_cd', 'observability'],
            ['ai_llm'],
        ];

        return min(1.0, count(array_filter($layers, fn (array $layer) => array_intersect($layer, $capabilities) !== [])) / 4);
    }

    /**
     * @param  array<string, float>  $s
     */
    private function reason(array $s): string
    {
        $labels = ['ownership' => 'owned end to end', 'architecture' => 'deliberate architecture', 'complexity' => 'broad technical surface', 'relevance' => 'matches what the market asks for', 'distinctiveness' => 'distinctive in this career', 'outcomes' => 'demonstrated outcomes'];
        arsort($s);
        $top = array_keys(array_filter(array_slice($s, 0, 3, true), fn (float $v) => $v >= 0.4));

        return $top === [] ? 'Supported but modest on every signal.' : ucfirst(implode(', ', array_map(fn (string $k) => $labels[$k], $top))).'.';
    }

    /**
     * Candidate resume bullets: one per claim, every role and project, ranked by the same signals. Practice
     * statements and whole-career facts are evidence for the profile, never bullets.
     *
     * @param  Collection<int, CareerFact>  $facts
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, array<string, float>>  $signals
     * @return array<int, array<string, mixed>>
     */
    private function accomplishments(Collection $facts, array $index, array $signals): array
    {
        $rows = [];

        foreach ($facts as $fact) {
            $entry = $index[$fact->key];

            if ($entry['whole_career'] || $entry['role_id'] === null || preg_match('/^(?:Uses|Consistently uses|[A-Z][\w\'’]+(?:\'s)? (?:engineering )?[\w -]*\buses\b)/', $fact->statement) === 1) {
                continue;
            }

            $s = $signals[$fact->key];
            $platformUse = $s['outcome'] === 0.0 && preg_match('/^(?:Used|Worked with|Helped|Served as|Collaborated)\b/', $fact->statement) === 1;
            $kind = match (true) {
                $platformUse => 'platform_use',
                $s['outcome'] >= 0.7 => 'outcome',
                $s['ownership'] > 0 => 'ownership',
                default => 'implementation',
            };
            $score = 0.30 * min(1.0, $s['relevance'] * 2) + 0.20 * $s['ownership'] + 0.20 * $s['architecture'] + 0.20 * $s['outcome'] + 0.10 * min(1.0, count($entry['capabilities']) / 3);

            // "Used GCP" or "Worked with Azure" says a technology was touched, not what was built: a skill, not an accomplishment.
            if ($platformUse) {
                $score *= 0.6;
            }

            $rows[] = [
                'id' => "accomplishment:{$fact->key}", 'fact_key' => $fact->key, 'project_id' => $entry['project_id'], 'role_id' => $entry['role_id'],
                'kind' => $kind, 'score' => round($score, 3), 'visibility' => $entry['visibility'], 'capabilities' => $entry['capabilities'], 'shared_with' => $entry['shared_with'],
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(fn (array $r, int $i) => [...$r, 'rank' => $i + 1], $rows, array_keys($rows));
    }

    /**
     * @param  array<int, array<string, mixed>>  $accomplishments
     * @param  array<int, array<string, mixed>>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function roles(int $careerId, array $accomplishments, array $projects): array
    {
        $roles = Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $careerId))->with('employer')->get();
        $out = [];

        foreach ($roles as $role) {
            $own = array_values(array_filter($accomplishments, fn (array $a) => $a['role_id'] === $role->id));

            if ($own === []) {
                continue;
            }

            $out[] = [
                'id' => "role:{$role->id}", 'role_id' => $role->id, 'title' => $role->title, 'employer' => $role->employer->name, 'start_year' => $role->start_year, 'end_year' => $role->end_year,
                // How much of the profile's strongest evidence this role holds; drives how many bullets it gets.
                'relevance' => round(array_sum(array_map(fn (array $a) => $a['score'], array_slice($own, 0, 6))), 3),
                'accomplishment_count' => count($own),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['relevance'] <=> $a['relevance']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @return array<string, mixed>
     */
    private function provenance(ResumeProfile $profile, array $aggregate, int $evidenceRevision): array
    {
        $sample = $profile->currentSample;

        return [
            'evidence_revision' => $evidenceRevision,
            'sample' => $sample === null ? null : [
                'id' => $sample->id, 'pilot' => (bool) $sample->pilot, 'postings' => (int) ($aggregate['postings'] ?? 0), 'companies' => (int) ($aggregate['companies'] ?? 0),
                'providers' => array_keys((array) ($aggregate['providers'] ?? [])), 'seniority' => (array) ($aggregate['seniority'] ?? []),
                'newest' => $aggregate['newest_posted_at'] ?? null, 'oldest' => $aggregate['oldest_posted_at'] ?? null, 'extraction_version' => $aggregate['version'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $aggregate
     * @param  array<string, array<string, mixed>>  $coverage
     * @return array<int, array<string, mixed>>
     */
    private function marketPriorities(array $aggregate, array $coverage): array
    {
        $total = max(1, (int) ($aggregate['postings'] ?? 0));
        $rows = [];

        foreach ((array) ($aggregate['capabilities'] ?? []) as $key => $c) {
            if ((int) $c['postings'] < 2) {
                continue;
            }

            $rows[] = [
                'key' => (string) $key, 'label' => (string) $c['label'], 'category' => (string) $c['category'], 'postings' => (int) $c['postings'], 'share' => round($c['postings'] / $total, 2),
                'required' => (int) $c['required'], 'status' => $coverage[$key]['status'] ?? CapabilityCoverage::NONE, 'depth' => $coverage[$key]['depth']['level'] ?? null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['postings'], $b['required']] <=> [$a['postings'], $a['required']]);

        return array_slice($rows, 0, 16);
    }

    /**
     * Direct capabilities (stated by an eligible fact) are kept apart from transferable ones (only a related
     * capability is supported). Only direct ones may later be phrased as experience.
     *
     * @param  array<string, array<string, mixed>>  $coverage
     * @param  array<string, mixed>  $aggregate
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, array<string, float>>  $signals
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function capabilities(array $coverage, array $aggregate, array $index, array $signals): array
    {
        $total = max(1, (int) ($aggregate['postings'] ?? 0));
        $out = ['direct' => [], 'transferable' => [], 'underdocumented' => []];

        foreach ($coverage as $key => $c) {
            $market = (int) ($aggregate['capabilities'][$key]['postings'] ?? 0);
            $entry = ['id' => "capability:{$key}", 'key' => (string) $key, 'label' => $c['label'], 'category' => $c['category'], 'market_postings' => $market, 'market_share' => round($market / $total, 2), 'depth' => $c['depth']['level']];

            if ($c['status'] === CapabilityCoverage::DIRECT) {
                $keys = array_keys(array_filter($index, fn (array $f) => in_array($key, $f['capabilities'], true)));
                usort($keys, fn (string $a, string $b) => ($signals[$b]['ownership'] + $signals[$b]['outcome'] + $signals[$b]['architecture']) <=> ($signals[$a]['ownership'] + $signals[$a]['outcome'] + $signals[$a]['architecture']));
                $out['direct'][] = [...$entry, 'fact_keys' => array_slice($keys, 0, 6)];
            } elseif ($c['status'] === CapabilityCoverage::TRANSFERABLE) {
                $out['transferable'][] = [...$entry, 'via_fact_keys' => array_column($c['facts'], 'key')];
            } elseif ($c['status'] === CapabilityCoverage::UNDERDOCUMENTED) {
                $out['underdocumented'][] = $entry;
            }
        }

        foreach ($out as $status => $list) {
            usort($list, fn (array $a, array $b) => [$b['market_postings'], $b['depth'] === 'deep' ? 1 : 0] <=> [$a['market_postings'], $a['depth'] === 'deep' ? 1 : 0]);
            $out[$status] = $list;
        }

        return $out;
    }
}
