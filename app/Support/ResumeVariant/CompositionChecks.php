<?php

namespace App\Support\ResumeVariant;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Support\ResumeProfile\CapabilityCoverage;

/**
 * Whole-resume checks on a selection, before any wording. Three, each answering one question a reviewer would ask:
 *
 *   engineering_evidence  Does the resume show software the candidate actually designed or built?
 *   result_context        Is every measured result selected together with what produced it?
 *   narrative_consistency Is each requirement the resume leads with backed by the kind of evidence it will be worded as?
 *
 * Each uses relationships the record already has (a fact's project, its claim group, the action it states, the
 * capabilities a requirement and a fact share) and nothing else. Where those relationships do not support a
 * conclusion, no finding is made. Findings never change the selection: they say what is wrong, which selected
 * facts caused it, and which eligible facts are available, and a person decides.
 *
 * Severity: `blocking` is reserved for an objectively unsupported or disallowed claim. Selection can only draw on
 * eligible facts, so nothing here reaches it; the wording-stage validators own that. `review` is a substantive
 * quality concern; `informational` is an optional improvement.
 *
 * Deterministic; no model call.
 */
final class CompositionChecks
{
    public const BLOCKING = 'blocking';

    public const REVIEW = 'review';

    public const INFORMATIONAL = 'informational';

    private const BUILD = ['implementation'];

    private const HANDS_ON = ['implementation', 'ownership', 'integration', 'configuration'];

    private const AVAILABLE = 3;

    public function __construct(private readonly ClaimScopeChecker $scope, private readonly CapabilityCoverage $coverage) {}

    /**
     * @param  array<string, mixed>  $content  the selection: experience[].bullet_groups[] (career_fact_keys, job_analysis_finding_ids), summary_evidence
     * @param  array<string, array<string, mixed>>  $facts  eligible facts by key: statement, attribution.project_id, metric
     * @param  array<string, int>  $claimGroupSize  fact key => how many places state the same claim
     * @param  array<int, array<string, mixed>>  $accomplishments  the profile's ranked accomplishments (fact_key, kind), best first
     * @param  array{engineering: bool, findings: array<int, array<string, mixed>>, priority: array<int, int>}  $job  findings carry id, label, category, statement, capabilities
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    public function run(array $content, array $facts, array $claimGroupSize, array $accomplishments, array $job): array
    {
        $groups = [];

        foreach ($content['experience'] as $role) {
            foreach ($role['bullet_groups'] as $group) {
                $groups[] = $group;
            }
        }

        $selected = array_values(array_unique(array_merge(array_merge(...array_column($groups, 'career_fact_keys') ?: [[]]), $content['summary_evidence'] ?? [])));
        $findings = [];

        if ($job['engineering']) {
            array_push($findings, ...$this->engineeringEvidence($groups, $content['summary_evidence'] ?? [], $selected, $facts, $claimGroupSize, $accomplishments));
        }

        array_push($findings, ...$this->resultContext($groups, $facts, $selected, $accomplishments));
        array_push($findings, ...$this->narrativeConsistency($selected, $facts, $job));

        return $findings;
    }

    /**
     * Run the checks for a profile-assisted resume, deriving the job and profile inputs the same way generation does.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<string, mixed>  $effective  the profile as adapted to the job (accomplishments, job_priority_findings)
     * @param  array<string, mixed>  $relevance  JobRelevance::analyze()
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    public function forJob(array $content, array $facts, CareerProfile $profile, array $effective, array $relevance, JobAnalysis $analysis): array
    {
        return $this->run($content, $facts, $this->claimGroupSizes($profile), $effective['accomplishments'], [
            'engineering' => preg_match('/engineer|developer|architect/i', (string) $analysis->jobPosting->title) === 1,
            'findings' => $relevance['findings'],
            'priority' => $effective['job_priority_findings'] ?? [],
        ]);
    }

    /**
     * How many places state each claim: a claim spread over several projects is a general statement, not one project's work.
     *
     * @return array<string, int>
     */
    public function claimGroupSizes(CareerProfile $profile): array
    {
        return CareerFact::query()->where('career_profile_id', $profile->id)->whereNotNull('claim_group')->get(['key', 'claim_group'])
            ->groupBy('claim_group')->flatMap(fn ($group) => $group->mapWithKeys(fn (CareerFact $f) => [$f->key => $group->count()]))->all();
    }

    /**
     * A concrete build: a fact tied to a project that says the candidate designed or built something, and whose
     * claim is stated in one place. A technology statement repeated across projects, an ownership statement and a
     * role-level claim are not.
     *
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<string, int>  $claimGroupSize
     */
    public function isConcreteBuild(string $key, array $facts, array $claimGroupSize): bool
    {
        $fact = $facts[$key] ?? null;

        return $fact !== null
            && ($fact['attribution']['project_id'] ?? null) !== null
            && ($claimGroupSize[$key] ?? 1) === 1
            && array_intersect(self::BUILD, $this->scope->actionClasses((string) $fact['statement'])) !== [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $summary
     * @param  array<int, string>  $selected
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<string, int>  $claimGroupSize
     * @param  array<int, array<string, mixed>>  $accomplishments
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    private function engineeringEvidence(array $groups, array $summary, array $selected, array $facts, array $claimGroupSize, array $accomplishments): array
    {
        $isBuild = fn (string $key): bool => $this->isConcreteBuild($key, $facts, $claimGroupSize);
        $inBullets = array_values(array_filter(array_unique(array_merge(...array_column($groups, 'career_fact_keys') ?: [[]])), $isBuild));
        $inSummary = array_values(array_filter($summary, $isBuild));
        $available = $this->available($accomplishments, $selected, $isBuild);
        $findings = [];

        if ($inBullets === []) {
            $findings[] = $this->finding('engineering_evidence', self::REVIEW, 'No bullet shows software the candidate designed or built. Technology statements, ownership statements and role-level claims do not count.', [], $available);
        }

        if ($inSummary === []) {
            $findings[] = $this->finding(
                'engineering_evidence',
                self::REVIEW,
                'The summary rests on no concrete build: its evidence is technology tags, ownership statements or other claims, so it can say the candidate owns systems without naming one.',
                $summary,
                array_slice(array_values(array_unique([...$inBullets, ...$available])), 0, self::AVAILABLE),
            );
        }

        return $findings;
    }

    /**
     * A measured result needs the work that produced it in the same bullet. The record says what produced a result
     * only through the project they share, so only a project's results are judged; a role-level result has no
     * recorded producer and is noted, not judged.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $selected
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<int, array<string, mixed>>  $accomplishments
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    private function resultContext(array $groups, array $facts, array $selected, array $accomplishments): array
    {
        $isResult = fn (string $key): bool => ($facts[$key]['metric'] ?? null) !== null;
        $findings = [];

        foreach ($groups as $group) {
            $keys = array_values(array_filter($group['career_fact_keys'], fn (string $k) => isset($facts[$k])));

            if ($keys === [] || array_filter($keys, fn (string $k) => ! $isResult($k)) !== []) {
                continue;
            }

            $project = $facts[$keys[0]]['attribution']['project_id'] ?? null;

            if ($project === null) {
                $findings[] = $this->finding('result_context', self::INFORMATIONAL, 'A result is selected alone, and the record links it to no work that produced it, so the bullet will state the figure without saying how it came about.', $keys, []);

                continue;
            }

            $producers = array_values(array_filter(
                array_column($accomplishments, 'fact_key'),
                fn (string $k) => isset($facts[$k]) && ($facts[$k]['attribution']['project_id'] ?? null) === $project && ! $isResult($k),
            ));
            $elsewhere = array_values(array_intersect($producers, $selected));

            $findings[] = $this->finding(
                'result_context',
                self::REVIEW,
                $elsewhere !== []
                    ? 'A result is selected apart from the same project\'s work, which is in another bullet.'
                    : 'A result is selected without any of the work from its project that produced it.',
                $keys,
                array_slice($elsewhere !== [] ? $elsewhere : $producers, 0, self::AVAILABLE),
            );
        }

        return $findings;
    }

    /**
     * What the resume leads with must be backed by the kind of evidence it will be worded as. For each capability a
     * leading requirement names, the selected facts that state it are read for what the candidate did: if all of them
     * only supported, advised on or troubleshot it, the resume must not present it as built or owned. A capability
     * no selected fact states, or one whose facts say nothing about the action, is not judged here: those are gaps,
     * which the job tailoring already reports.
     *
     * @param  array<int, string>  $selected
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array{engineering: bool, findings: array<int, array<string, mixed>>, priority: array<int, int>}  $job
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    private function narrativeConsistency(array $selected, array $facts, array $job): array
    {
        $byId = array_column($job['findings'], null, 'id');
        $statements = array_map(fn (string $k) => (string) ($facts[$k]['statement'] ?? ''), array_combine($selected, $selected));
        $capsOf = array_map(fn (string $s) => $this->coverage->capabilitiesIn($s), $statements);
        $judged = [];
        $merged = [];

        foreach ($job['priority'] as $id) {
            foreach ((array) ($byId[$id]['capabilities'] ?? []) as $capability) {
                if (isset($judged[$capability])) {
                    continue;
                }

                $judged[$capability] = true;
                $answering = array_values(array_filter($selected, fn (string $k) => in_array($capability, $capsOf[$k], true)));
                $classes = array_values(array_unique(array_merge([], ...array_map(fn (string $k) => $this->scope->actionClasses($statements[$k]), $answering))));

                if ($answering !== [] && $classes !== [] && array_intersect($classes, self::HANDS_ON) === []) {
                    // One fact answering several requirements is one problem, not several.
                    $merged[implode('|', $answering)]['keys'] = $answering;
                    $merged[implode('|', $answering)]['classes'] = $classes;
                    $merged[implode('|', $answering)]['asks'][] = '"'.str_replace('_', ' ', (string) $byId[$id]['label']).'"';
                }
            }
        }

        $findings = [];

        foreach ($merged as $item) {
            $asks = implode(', ', array_unique($item['asks']));
            $findings[] = $this->finding(
                'narrative_consistency',
                self::REVIEW,
                "The resume leads with {$asks}, but the selected evidence for it shows only supporting or troubleshooting work ("
                .implode(', ', $item['classes']).'). It must not be presented as built or owned.',
                $item['keys'],
                [],
            );
        }

        return $findings;
    }

    /**
     * Eligible facts that would address a finding, in the profile's own order.
     *
     * @param  array<int, array<string, mixed>>  $accomplishments
     * @param  array<int, string>  $selected
     * @return array<int, string>
     */
    private function available(array $accomplishments, array $selected, \Closure $qualifies): array
    {
        $out = [];
        $projects = [];

        foreach ($accomplishments as $a) {
            $key = (string) $a['fact_key'];

            if (in_array($key, $selected, true) || ! $qualifies($key) || isset($projects[$a['project_id'] ?? 0])) {
                continue;
            }

            $projects[$a['project_id'] ?? 0] = true;
            $out[] = $key;

            if (count($out) >= self::AVAILABLE) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $selected
     * @param  array<int, string>  $available
     * @return array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}
     */
    private function finding(string $check, string $severity, string $message, array $selected, array $available): array
    {
        return ['check' => $check, 'severity' => $severity, 'message' => $message, 'selected' => array_values($selected), 'available' => array_values($available)];
    }
}
