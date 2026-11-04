<?php

namespace App\Support\JobTailoring;

use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Support\ResumeProfile\CapabilityCoverage;

/**
 * What a job asks for and which approved facts speak to it. Deterministic, built from the job analysis that already
 * exists (its findings), never from a model call and never from another candidate analysis.
 *
 * A fact is relevant to a finding when it states a capability the finding names (webhooks, payment gateways, recurring
 * billing, conversion funnels, React ...) or shares specific words with it. Relevance ranks facts; it authorizes nothing.
 * Claims are still judged by the existing wording validators against the cited facts.
 *
 * A "gap" is a capability a finding names that the approved record does not support directly: either nothing states it,
 * or the user confirmed no direct experience (Ruby on Rails). Gaps are reported so the wording never implies them.
 */
final class JobRelevance
{
    /** How much a kind of finding says about what the resume should lead with. */
    private const WEIGHT = [
        'responsibility' => 1.0, 'required_qualification' => 1.0, 'technology' => 0.7,
        'domain_knowledge' => 0.6, 'preferred_qualification' => 0.6, 'success_measure' => 0.25,
    ];

    private const STOP = ['which', 'their', 'there', 'these', 'those', 'other', 'while', 'where', 'would', 'about', 'after', 'being', 'every', 'using', 'across', 'through', 'within', 'between', 'during', 'could', 'should', 'experience', 'strong', 'working', 'engineering', 'software', 'engineer', 'team', 'teams', 'platform', 'systems', 'system', 'build', 'builds', 'building', 'maintain', 'candidate', 'candidates', 'including', 'required', 'preferred', 'professional', 'years'];

    public function __construct(private readonly CapabilityCoverage $coverage) {}

    /**
     * @param  array<string, array<string, mixed>>  $facts  inventory facts, keyed by fact key
     * @return array{findings: array<int, array<string, mixed>>, facts: array<string, array<string, mixed>>, capabilities: array<string, float>, gaps: array<int, array<string, mixed>>}
     */
    public function analyze(JobAnalysis $analysis, array $facts, CareerProfile $career): array
    {
        $findings = [];

        foreach ($analysis->findings as $finding) {
            $weight = self::WEIGHT[$finding->category->value] ?? null;

            if ($weight === null) {
                continue;
            }

            $weight *= match ($finding->emphasis?->value) {
                'high' => 1.2, 'low' => 0.8, default => 1.0,
            };

            $findings[$finding->id] = [
                'id' => $finding->id, 'label' => (string) $finding->label, 'category' => $finding->category->value,
                'statement' => (string) $finding->statement, 'weight' => $weight, 'capabilities' => $this->coverage->capabilitiesIn((string) $finding->statement),
            ];
        }

        // A capability that few facts state says more about a fact than one that half the record states (conversion
        // funnels, for a career with years of marketing work), so each capability is weighted by how rare it is.
        $pool = array_filter($facts, fn (array $f) => ! ($f['support_only'] ?? false));
        $idf = [];

        foreach (array_unique(array_merge(...array_map(fn (array $f) => $f['capabilities'], $findings ?: [['capabilities' => []]]))) as $cap) {
            $df = count(array_filter($pool, fn (array $f) => in_array($cap, (array) $f['capabilities'], true)));
            $idf[$cap] = 1.0 + log((count($pool) + 1) / ($df + 1));
        }

        $capShare = [];

        foreach ($findings as $f) {
            foreach ($f['capabilities'] as $cap) {
                $capShare[$cap] = max($capShare[$cap] ?? 0.0, $f['weight'] / sqrt(count($f['capabilities'])));
            }
        }

        // The job's own vocabulary (payment, onboarding, webhook ...), each word weighted by the findings that use it and by
        // how rare it is across the record. Words are evidence of relevance, never of a claim.
        $vocab = [];

        foreach ($findings as $f) {
            foreach ($this->stems($f['statement']) as $stem) {
                $vocab[$stem] = ($vocab[$stem] ?? 0.0) + $f['weight'];
            }
        }

        $factStems = array_map(fn (array $f) => $this->stems((string) $f['statement']), $pool);
        $df = [];

        foreach ($factStems as $stems) {
            foreach (array_intersect($stems, array_keys($vocab)) as $stem) {
                $df[$stem] = ($df[$stem] ?? 0) + 1;
            }
        }

        $score = [];
        $because = [];

        foreach ($pool as $key => $fact) {
            foreach (array_intersect(array_keys($capShare), (array) $fact['capabilities']) as $cap) {
                $score[$key] = ($score[$key] ?? 0.0) + $idf[$cap] * $capShare[$cap];
            }

            $words = 0.0;

            foreach (array_intersect($factStems[$key], array_keys($vocab)) as $stem) {
                $words += min(1.5, $vocab[$stem]) * log((count($pool) + 1) / (($df[$stem] ?? 0) + 1)) / 4;
            }

            if ($words > 0) {
                $score[$key] = ($score[$key] ?? 0.0) + min(1.0, $words);
            }

            foreach ($findings as $f) {
                $shared = count(array_intersect($f['capabilities'], (array) $fact['capabilities']));
                $overlap = count(array_intersect($this->stems($f['statement']), $factStems[$key]));
                $value = ($shared > 0 ? $f['weight'] / sqrt(count($f['capabilities'])) : 0.0) + 0.1 * $overlap * $f['weight'];

                if ($value > 0.15) {
                    $because[$key][$f['id']] = $value;
                }
            }

            if (($because[$key] ?? []) === [] && isset($score[$key])) {
                $because[$key] = [];
            }
        }

        $capabilityWeight = [];

        foreach ($capShare as $cap => $share) {
            $capabilityWeight[$cap] = $idf[$cap] * $share;
        }

        $employment = array_filter($score, fn (float $v, string $k) => ! ($facts[$k]['personal'] ?? false), ARRAY_FILTER_USE_BOTH);
        $max = $employment === [] ? 1.0 : max($employment);
        $relevant = [];
        $normalized = array_map(fn (float $v) => min(1.0, $v / $max), $score);

        // Evidence from the same engagement as strongly relevant evidence is relevant context (merchant onboarding next to
        // payment webhooks): a fact takes a share of the strength of its role's best facts. Context only lifts; it never
        // makes a fact state anything it does not.
        $byRole = [];

        foreach ($normalized as $key => $value) {
            if (isset($facts[$key]['role_id'])) {
                $byRole[$facts[$key]['role_id']][] = $value;
            }
        }

        $roleStrength = array_map(function (array $values) {
            rsort($values);

            return array_sum(array_slice($values, 0, 3)) / 3;
        }, $byRole);

        foreach ($pool as $key => $fact) {
            $context = isset($fact['role_id']) ? 0.3 * ($roleStrength[$fact['role_id']] ?? 0.0) : 0.0;
            $total = min(1.0, ($normalized[$key] ?? 0.0) + $context);

            if ($total <= 0.0) {
                continue;
            }

            $why = $because[$key] ?? [];
            arsort($why);
            $relevant[$key] = ['score' => round($total, 3), 'finding_ids' => array_slice(array_keys($why), 0, 3)];
        }

        $top = $capabilityWeight === [] ? 1.0 : max($capabilityWeight);

        return [
            'findings' => $findings,
            'facts' => $relevant,
            'capabilities' => array_map(fn (float $w) => round($w / $top, 3), $capabilityWeight),
            'gaps' => $this->gaps($findings, $career),
        ];
    }

    /**
     * Requirements the approved record does not support directly. A preferred qualification that is missing is a gap
     * too, but never a reason to claim it.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function gaps(array $findings, CareerProfile $career): array
    {
        $coverage = $this->coverage->compute($career)['capabilities'];
        $gaps = [];

        foreach ($findings as $f) {
            foreach ($f['capabilities'] as $cap) {
                $status = $coverage[$cap]['status'] ?? null;

                if (in_array($status, [CapabilityCoverage::NONE, CapabilityCoverage::LIMITATION, CapabilityCoverage::UNDERDOCUMENTED, CapabilityCoverage::TRANSFERABLE], true)) {
                    $gaps[$cap] ??= [
                        'capability' => $cap, 'label' => (string) config("resume_profiles.capabilities.{$cap}.label", $cap), 'status' => $status,
                        'finding_id' => $f['id'], 'finding' => $f['label'], 'required' => in_array($f['category'], ['required_qualification', 'responsibility'], true),
                    ];
                }
            }
        }

        return array_values($gaps);
    }

    /**
     * @return array<int, string>
     */
    private function stems(string $text): array
    {
        $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [], fn (string $w) => strlen($w) >= 5 && ! in_array($w, self::STOP, true));

        return array_values(array_unique(array_map(fn (string $w) => substr($w, 0, 6), $words)));
    }
}
