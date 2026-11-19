<?php

namespace App\Support\JobTailoring;

use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Support\ProfileSynthesis\StoryRole;
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
     * @return array{findings: array<int, array<string, mixed>>, engineering: bool, title: string, facts: array<string, array<string, mixed>>, capabilities: array<string, float>, gaps: array<int, array<string, mixed>>}
     */
    public function analyze(JobAnalysis $analysis, array $facts, CareerProfile $career): array
    {
        $findings = [];
        $engineering = preg_match('/engineer|developer|architect|programmer/i', (string) $analysis->jobPosting?->title) === 1;

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
        // Capabilities are read from the statements now, not from the stored inventory: an approved profile keeps the
        // capabilities it was built with, and matching should not depend on how old that inventory is.
        $pool = array_map(
            fn (array $f) => [...$f, 'capabilities' => array_values(array_unique([...(array) $f['capabilities'], ...$this->coverage->capabilitiesIn((string) $f['statement'])]))],
            array_filter($facts, fn (array $f) => ! ($f['support_only'] ?? false)),
        );
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

        $because = [];
        $capEvidence = [];
        $wordEvidence = [];

        foreach ($pool as $key => $fact) {
            // The strongest capability the fact states counts fully; further ones add less (a broad job names dozens).
            $caps = array_map(fn (string $cap) => $idf[$cap] * $capShare[$cap], array_intersect(array_keys($capShare), (array) $fact['capabilities']));

            if ($caps !== []) {
                $capEvidence[$key] = max($caps) + 0.35 * (array_sum($caps) - max($caps));
            }

            foreach (array_intersect($factStems[$key], array_keys($vocab)) as $stem) {
                $wordEvidence[$key] = ($wordEvidence[$key] ?? 0.0) + min(1.5, $vocab[$stem]) * log((count($pool) + 1) / (($df[$stem] ?? 0) + 1)) / 4;
            }

            foreach ($findings as $f) {
                $shared = count(array_intersect($f['capabilities'], (array) $fact['capabilities']));
                $overlap = count(array_intersect($this->stems($f['statement']), $factStems[$key]));
                $value = ($shared > 0 ? $f['weight'] / sqrt(count($f['capabilities'])) : 0.0) + 0.1 * $overlap * $f['weight'];

                // A fact answers a finding only when it speaks to it substantially, not on one shared word.
                if ($value >= 0.4 * $f['weight']) {
                    $because[$key][$f['id']] = $value;
                }
            }
        }

        // Capability evidence and vocabulary evidence each saturate (more of the same adds less) and combine, so one kind
        // cannot swamp the other and a strong fact is not clamped to the same ceiling as a middling one.
        $score = [];

        foreach ($pool as $key => $fact) {
            $c = ($capEvidence[$key] ?? 0.0) / (($capEvidence[$key] ?? 0.0) + 6.0);
            $w = ($wordEvidence[$key] ?? 0.0) / (($wordEvidence[$key] ?? 0.0) + 4.0);
            $score[$key] = 1.0 - (1.0 - $c) * (1.0 - $w);
        }

        $capabilityWeight = [];

        foreach ($capShare as $cap => $share) {
            $capabilityWeight[$cap] = $idf[$cap] * $share;
        }

        $relevant = [];
        $normalized = $score;

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
            $context = isset($fact['role_id']) ? 0.1 * ($roleStrength[$fact['role_id']] ?? 0.0) : 0.0;
            $total = min(1.0, ($normalized[$key] ?? 0.0) + $context);

            if ($total <= 0.0) {
                continue;
            }

            $why = $because[$key] ?? [];
            arsort($why);
            [$factor, $adjusted] = $this->specificity($fact, $engineering);
            $relevant[$key] = ['score' => round(min(1.0, $total * $factor), 3), 'finding_ids' => array_slice(array_keys($why), 0, 3), 'adjustments' => $adjusted];
        }

        $top = $capabilityWeight === [] ? 1.0 : max($capabilityWeight);

        return [
            'findings' => $findings,
            'engineering' => $engineering,
            'title' => (string) $analysis->jobPosting?->title,
            'facts' => $relevant,
            'capabilities' => array_map(fn (float $w) => round($w / $top, 3), $capabilityWeight),
            'gaps' => $this->gaps($findings, $career),
        ];
    }

    /**
     * Whether a fact's recorded adjustments mark it as a general statement of ownership or leadership: positioning built on
     * it says the same thing as every other resume ("owned it end to end") rather than what this candidate built.
     *
     * @param  array<string, mixed>  $relevance  analyze()
     */
    public static function isGeneralClaim(array $relevance, string $factKey): bool
    {
        return array_intersect((array) ($relevance['facts'][$factKey]['adjustments'] ?? []), ['generic_ownership', 'generic_leadership']) !== [];
    }

    /**
     * A small, explainable correction for what keyword and capability overlap cannot see: whether a fact is demonstrated
     * work or a general statement. General ownership and leadership prose, a bare platform mention and (for an engineering
     * job) marketing-only results are discounted; a concrete build in a named system, an integration, a deployment to
     * real users and a project's measured result are credited. Age is never a factor. The factors are in
     * config('resume.composition.relevance'); the labels of those applied are returned so a ranking can be explained.
     *
     * @param  array<string, mixed>  $fact  an inventory fact
     * @return array{0: float, 1: array<int, string>}
     */
    private function specificity(array $fact, bool $engineeringJob): array
    {
        $cfg = (array) config('resume.composition.relevance');
        $text = trim((string) $fact['statement']);
        $caps = (array) $fact['capabilities'];
        $project = ($fact['project_id'] ?? null) !== null;
        $buildVerb = preg_match('/^(?:Built|Developed|Implemented|Integrated|Designed|Automated|Created|Engineered|Normalized|Extended)\b/', $text) === 1;
        $integration = preg_match('/\b(?:APIs?|integrat\w+|webhooks?|pipelines?|data warehouse|retriev\w+)\b/i', $text) === 1;
        $builtSoftware = $buildVerb && preg_match('/\b(?:platform|application|app|dashboard|tool|system|portal|service|pipeline|workflows?)\b/i', $text) === 1;
        $engineering = $integration || $builtSoftware || preg_match('/\b(?:backend|frontend|database|SQL|React|Node|Laravel|TypeScript|architect\w*|deploy\w*|migrat\w+)\b/i', $text) === 1;
        $applied = [];
        $factor = 1.0;
        $apply = function (string $label) use (&$factor, &$applied, $cfg): void {
            $factor *= (float) $cfg[$label];
            $applied[] = $label;
        };

        if (preg_match('/\btechnical leadership\b|\bmentor\w*/i', $text) === 1 || in_array('mentoring_leadership', $caps, true)) {
            $apply('generic_leadership');
        } elseif (preg_match('/^(?:Independently\s+)?(?:owned|designed and implemented the technical solution)\b/i', $text) === 1) {
            $apply('generic_ownership');
        }

        if ($caps !== [] && array_diff($caps, ['cloud_platforms', 'docker']) === [] && (! $project || ! $buildVerb)) {
            $apply('platform_tag');
        }

        // "Partnered with marketing, finance and engineering stakeholders to turn requirements into full-stack applications" names
        // marketing as one of the people worked with; the work is engineering. Only a statement about marketing itself is marketing-only.
        $crossFunctional = preg_match('/\b(?:full-stack|software|applications?|engineering)\b/i', $text) === 1;

        if ($engineeringJob && ! $engineering && ! $crossFunctional && preg_match('/\b(?:SEO|search engine|CRO|conversion|lead generation|landing page|paid|campaign\w*|marketing|A\/B|keyword|organic|attribution)\b/i', $text) === 1) {
            $apply('marketing_only');
        }

        if ($project && $buildVerb && $engineering) {
            $apply('concrete_build');
        } elseif ($integration && $buildVerb) {
            $apply('integration');
        }

        if (StoryRole::isDeployment($text)) {
            $apply('deployed');
        }

        if ($project && StoryRole::isOutcome($text)) {
            $apply('project_outcome');
        }

        return [max((float) $cfg['min'], min((float) $cfg['max'], $factor)), $applied];
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
