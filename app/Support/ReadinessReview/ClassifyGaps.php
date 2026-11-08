<?php

namespace App\Support\ReadinessReview;

use App\Models\CareerFact;
use App\Models\ResumeVariant;
use App\Models\Role;
use App\Support\JobTailoring\JobRelevance;
use App\Support\ProfileSynthesis\EffectiveProfile;
use App\Support\ResumeProfile\CapabilityCoverage;
use App\Support\ResumeVariant\ResumeCandidatePayloadBuilder;
use App\Support\ResumeVariant\RoleEligibleFacts;

/**
 * Separates "the resume leaves it out" from "the record does not establish it", from the approved career record and
 * never from the model. For each requirement the resume does not clearly cover:
 *
 *   omitted_available  an eligible fact states a capability the requirement names and is not on the resume
 *   transferable       only related experience is approved: it must not be presented as the thing itself
 *   not_established    no approved fact supports it (or the candidate confirmed no direct experience)
 *
 * Only `omitted_available` suggests adding anything, and only a fact that is resume-eligible (Private never is), eligible
 * for a role this resume has, allowed by the approved profile, and not already represented. A qualification gap is never
 * offered as wording to insert. Deterministic; no model call.
 */
final class ClassifyGaps
{
    private const CANDIDATES = 3;

    public function __construct(
        private readonly CapabilityCoverage $coverage,
        private readonly ResumeCandidatePayloadBuilder $candidates,
        private readonly RoleEligibleFacts $eligible,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $requirements  validated requirements
     * @param  array<int, string>  $resumeFactKeys  facts cited anywhere on the resume
     * @param  array<string, mixed>  $relevance  JobRelevance::analyze()
     * @return array{requirements: array<string, array<string, mixed>>, terms: array<int, array<string, mixed>>}
     */
    public function classify(ResumeVariant $variant, array $requirements, array $resumeFactKeys, array $relevance, string $pdfText): array
    {
        $variant->loadMissing(['careerProfile', 'synthesis', 'experienceRoles']);
        $profile = $variant->careerProfile;
        $facts = collect($this->candidates->buildForCareer($profile)['career_facts'])->keyBy('key')->all();
        $coverage = $this->coverage->compute($profile)['capabilities'];
        $roles = Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profile->id))->with('projects')->get();
        $eligibleByRole = $this->eligible->forRoles($roles, $profile);
        $resumeRoleIds = $variant->experienceRoles->pluck('role_id')->all();
        $excluded = array_column(array_filter(EffectiveProfile::resolve($variant->synthesis)['accomplishments'], fn (array $a) => ! $a['included']), 'fact_key');
        $groups = CareerFact::query()->where('career_profile_id', $profile->id)->pluck('claim_group', 'key')->all();
        $onResume = [];

        foreach ($resumeFactKeys as $key) {
            $onResume[$groups[$key] ?? $key] = true;
        }

        $findingCaps = array_column($relevance['findings'], 'capabilities', 'id');
        $label = fn (string $cap): string => (string) ($coverage[$cap]['label'] ?? $cap);

        // Facts that could be added: eligible for the resume, not excluded, not already represented, and placeable under a role it has.
        $addable = function (string $key) use ($facts, $excluded, $onResume, $groups, $eligibleByRole, $resumeRoleIds): ?array {
            $fact = $facts[$key] ?? null;

            if ($fact === null || $fact['fact_type'] === 'capability' || in_array($key, $excluded, true) || isset($onResume[$groups[$key] ?? $key])) {
                return null;
            }

            $roleId = $fact['attribution']['role_id'] ?? null;

            if ($roleId === null || ! in_array($roleId, $resumeRoleIds, true) || ! isset($eligibleByRole[$roleId][$key])) {
                return null;
            }

            return ['key' => $key, 'statement' => $fact['statement'], 'visibility' => $fact['visibility'], 'role_id' => $roleId, 'role' => $fact['attribution']['role'] ?? null];
        };

        // A fact must state enough of what the requirement names: with several capabilities named, one generic match is not enough.
        $find = function (array $caps, int $need = 1) use ($facts, $addable, $relevance): array {
            $found = [];

            foreach ($facts as $key => $fact) {
                if (count(array_intersect($caps, $this->coverage->capabilitiesIn($fact['statement']))) >= $need && ($row = $addable($key)) !== null) {
                    $found[] = [...$row, 'score' => (float) ($relevance['facts'][$key]['score'] ?? 0)];
                }
            }

            usort($found, fn (array $a, array $b) => $b['score'] <=> $a['score']);

            return array_slice($found, 0, self::CANDIDATES);
        };

        $out = [];

        foreach ($requirements as $r) {
            // The requirement's own words come first; its linked findings are only a fallback, because a finding can be broader.
            $caps = $this->coverage->capabilitiesIn($r['text']) ?: array_values(array_unique(array_merge([], ...array_map(fn (int $id) => (array) ($findingCaps[$id] ?? []), $r['finding_ids']))));
            $statuses = array_map(fn (string $c) => $coverage[$c]['status'] ?? CapabilityCoverage::NONE, $caps);
            $names = array_map($label, $caps);

            if ($r['coverage'] === 'strong') {
                $out[$r['id']] = ['status' => 'in_resume', 'qualification_gap' => false, 'capabilities' => $names, 'candidates' => [], 'adjacent' => [], 'reason' => 'The resume states it.'];

                continue;
            }

            $limited = array_values(array_filter($caps, fn (string $c) => ($coverage[$c]['status'] ?? null) === CapabilityCoverage::LIMITATION));

            if ($limited !== []) {
                $out[$r['id']] = ['status' => 'not_established', 'qualification_gap' => true, 'capabilities' => $names, 'candidates' => [], 'adjacent' => [], 'reason' => 'You have confirmed no direct experience with '.implode(', ', array_map($label, $limited)).'. This is a qualification gap, not wording to add.'];

                continue;
            }

            $direct = array_values(array_filter($caps, fn (string $c) => ($coverage[$c]['status'] ?? null) === CapabilityCoverage::DIRECT));
            $found = $direct === [] ? [] : $find($direct, min(count($direct), (int) ceil(count($caps) / 2)));

            if ($found !== []) {
                $out[$r['id']] = ['status' => 'omitted_available', 'qualification_gap' => false, 'capabilities' => $names, 'candidates' => $found, 'adjacent' => [], 'reason' => 'Your approved record states this, and the resume leaves it out.'];

                continue;
            }

            // Whatever the resume shows of it comes from approved facts: with nothing further to add, the evidence is real but limited.
            if ($r['coverage'] === 'partial') {
                $out[$r['id']] = $caps === []
                    ? ['status' => 'unmapped', 'qualification_gap' => false, 'capabilities' => [], 'candidates' => [], 'adjacent' => [], 'reason' => 'Your approved record has no capability to check this against; judge it from the cited passages.']
                    : ['status' => 'already_shown', 'qualification_gap' => true, 'capabilities' => $names, 'candidates' => [], 'adjacent' => [], 'reason' => 'Your approved record says no more about this than the resume already shows; the evidence is real but limited.'];

                continue;
            }

            $related = array_values(array_filter($caps, fn (string $c) => in_array($coverage[$c]['status'] ?? null, [CapabilityCoverage::TRANSFERABLE, CapabilityCoverage::UNDERDOCUMENTED], true)));

            if ($related !== []) {
                $adjacent = [];

                foreach ($related as $cap) {
                    foreach ((array) ($coverage[$cap]['facts'] ?? []) as $f) {
                        $adjacent[$f['key']] = ['key' => $f['key'], 'statement' => $f['statement'], 'visibility' => $f['visibility']];
                    }
                }

                $out[$r['id']] = ['status' => 'transferable', 'qualification_gap' => true, 'capabilities' => $names, 'candidates' => [], 'adjacent' => array_slice(array_values($adjacent), 0, self::CANDIDATES), 'reason' => 'Only related experience is approved. It can support a related claim; it is not this experience.'];

                continue;
            }

            $out[$r['id']] = ['status' => 'not_established', 'qualification_gap' => true, 'capabilities' => $names, 'candidates' => [], 'adjacent' => [], 'reason' => 'No approved fact establishes this.'];
        }

        // Job terms the record directly supports that the resume's text never uses; and job terms it cannot support.
        $terms = [];
        $weights = $relevance['capabilities'];
        arsort($weights);
        $inPdf = $this->coverage->capabilitiesIn($pdfText);

        foreach (array_slice(array_keys(array_filter($weights, fn (float $w) => $w >= 0.5)), 0, 12) as $cap) {
            if (in_array($cap, $inPdf, true) || ! isset($coverage[$cap])) {
                continue;
            }

            if ($coverage[$cap]['status'] === CapabilityCoverage::DIRECT && ($found = $find([$cap])) !== []) {
                $terms[] = ['capability' => $label($cap), 'status' => 'supported_not_in_resume', 'candidates' => $found];
            } elseif (in_array($coverage[$cap]['status'], [CapabilityCoverage::LIMITATION, CapabilityCoverage::NONE], true)) {
                $terms[] = ['capability' => $label($cap), 'status' => 'qualification_gap', 'candidates' => []];
            }
        }

        return ['requirements' => $out, 'terms' => $terms];
    }
}
