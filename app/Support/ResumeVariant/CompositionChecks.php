<?php

namespace App\Support\ResumeVariant;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Support\ProfileSynthesis\ReferenceSelection;
use App\Support\ProfileSynthesis\StoryDimensions;
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

    public function __construct(private readonly ClaimScopeChecker $scope, private readonly CapabilityCoverage $coverage, private readonly ReferenceSelection $selection) {}

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
        array_push($findings, ...$this->storyDimensions($selected, $facts, $accomplishments));

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
        return [
            ...$this->run($content, $facts, $this->claimGroupSizes($profile), $effective['accomplishments'], [
                'engineering' => preg_match('/engineer|developer|architect/i', (string) $analysis->jobPosting->title) === 1,
                'findings' => $relevance['findings'],
                'priority' => $effective['job_priority_findings'] ?? [],
            ]),
            // Read from the resume's own bullets, so a generated, revised and reviewed resume are all judged the same way.
            ...$this->roleCoverage(['roles' => $this->selection->coverageOf($content, $effective, $facts)]),
        ];
    }

    /**
     * A long role that ended below the minimum coverage (config('resume.composition.coverage')): reported, never padded. The reason
     * says what would change it. A role short because its approved evidence states few distinct responsibilities is advice to
     * record more (informational: nothing on this resume is wrong); one short with evidence available is a selection or budget
     * problem worth a look (review).
     *
     * @param  array<string, mixed>  $trace  ReferenceSelection::build()['trace']
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    public function roleCoverage(array $trace): array
    {
        $findings = [];

        foreach ((array) ($trace['roles'] ?? []) as $role) {
            $c = $role['coverage'] ?? null;

            if ($c === null || ($c['short_of'] ?? null) === null) {
                continue;
            }

            $head = sprintf('%s (%d years) has %d bullet%s, below the %d a role of that length keeps.', $c['title'], $c['years'], $c['achieved'], $c['achieved'] === 1 ? '' : 's', $c['short_of']);
            $message = match ($c['reason']) {
                'evidence' => sprintf('%s Approved evidence states %s for it. Record what else you did in this role (owning projects, working with clients, delivery) in Career Data to give it fuller coverage.', $head, $c['cover_size'] === 0 ? 'no distinct responsibility' : sprintf('only %d distinct responsibilit%s (%s)', $c['cover_size'], $c['cover_size'] === 1 ? 'y' : 'ies', implode(', ', $c['supported']))),
                'budget' => $head.' The resume is at its bullet ceiling, so the role was not given more.',
                default => $head.' Approved evidence for more was available but was not selected.',
            };

            $findings[] = $this->finding('role_coverage', $c['reason'] === 'evidence' ? self::INFORMATIONAL : self::REVIEW, $message, [], []);
        }

        return $findings;
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
     * Substantive duplication among WRITTEN bullets: two bullets that tell the reader the same thing about the same work.
     *
     * Text overlap alone proves nothing (two bullets about one platform legitimately share its name and stack), so a
     * pair is a duplicate only when the second adds too little that the first does not already say AND they concern the
     * same project, or when they state the same measured result, or when they repeat the same ownership claim. A second
     * bullet that brings its own words (a different design decision, integration, operation or result) is never flagged
     * for overlap.
     *
     * `confidence` is `high` when the later bullet restates the earlier one or repeats its result for the same project:
     * those carry a `drop` (the later bullet) that generation may apply without losing any claim. `medium` findings are
     * advice for a person. Each finding names the eligible facts not on the resume that could take the later bullet's place.
     *
     * @param  array<int, array{role_id: int, index: int, text: string, fact_keys: array<int, string>}>  $bullets  in resume order
     * @param  array<string, array<string, mixed>>  $facts  eligible facts by key (attribution.project_id, attribution.project)
     * @param  array<int, string>  $unused  eligible, shareable facts that are not on the resume, best first
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>, confidence: string, reason: string, bullets: array<int, array{role_id: int, index: int}>, drop: array{role_id: int, index: int}|null}>
     */
    public function duplicates(array $bullets, array $facts, array $unused = []): array
    {
        $threshold = (float) config('resume.composition.duplicates.restated_below_new_share', 0.35);
        $info = array_map(fn (array $b) => [
            'project' => $this->projectOf($b['fact_keys'], $facts),
            'words' => $this->contentWords($b['text'], $this->projectNameOf($b['fact_keys'], $facts)),
            'figures' => $this->figures($b['text']),
            'ownership' => preg_match('/\b(?:independently|owned|ownership|end[- ]to[- ]end)\b/i', $b['text']) === 1,
        ], $bullets);
        $findings = [];
        $dropped = [];

        for ($j = 1; $j < count($bullets); $j++) {
            for ($i = 0; $i < $j; $i++) {
                if (isset($dropped[$j]) || isset($dropped[$i])) {
                    continue;
                }

                $a = $info[$i];
                $b = $info[$j];
                $sameProject = $a['project'] !== null && $a['project'] === $b['project'];
                $shared = array_values(array_intersect($a['figures'], $b['figures']));
                $adds = $b['words'] === [] ? 0.0 : count(array_diff($b['words'], $a['words'])) / count($b['words']);
                $restated = $adds < $threshold;

                [$reason, $confidence] = match (true) {
                    $sameProject && $shared !== [] => ['repeated_result', 'high'],
                    $sameProject && $restated && count($b['words']) >= 5 => ['restated', 'high'],
                    $shared !== [] => ['repeated_result', 'medium'],
                    $restated && count($b['words']) >= 6 => ['restated', 'medium'],
                    $sameProject && $a['ownership'] && $b['ownership'] => ['repeated_ownership', 'medium'],
                    default => [null, null],
                };

                if ($reason === null) {
                    continue;
                }

                $where = $bullets[$i]['role_id'] === $bullets[$j]['role_id'] ? 'bullets '.($bullets[$i]['index'] + 1).' and '.($bullets[$j]['index'] + 1).' of the same role' : 'two bullets';
                $about = $sameProject ? ' about the same project' : '';
                $what = match ($reason) {
                    'repeated_result' => 'state the same result ('.implode(', ', $shared).')',
                    'repeated_ownership' => 'repeat the same ownership claim',
                    default => 'say largely the same thing',
                };
                $keys = array_values(array_unique([...$bullets[$i]['fact_keys'], ...$bullets[$j]['fact_keys']]));
                $alternatives = array_slice(array_values(array_diff($unused, $keys)), 0, self::AVAILABLE);
                $advice = $confidence === 'high' ? 'The later one adds no distinct evidence' : 'Keep the stronger or give the later one a different dimension (design, integration, operation or result)';
                $finding = $this->finding('duplicate_accomplishment', 'review', "{$where}{$about} {$what}. {$advice}.", $keys, $alternatives);
                $finding['confidence'] = $confidence;
                $finding['reason'] = $reason;
                $finding['bullets'] = [['role_id' => $bullets[$i]['role_id'], 'index' => $bullets[$i]['index']], ['role_id' => $bullets[$j]['role_id'], 'index' => $bullets[$j]['index']]];
                $finding['drop'] = $confidence === 'high' ? ['role_id' => $bullets[$j]['role_id'], 'index' => $bullets[$j]['index']] : null;
                $findings[] = $finding;

                if ($confidence === 'high') {
                    $dropped[$j] = true;
                }
            }
        }

        // The same claim of ownership, made again and again across the resume, is a pattern no single pair shows.
        $own = array_keys(array_filter($bullets, fn (array $b) => preg_match('/^\s*independently\b/i', $b['text']) === 1));

        if (count($own) >= 2) {
            $finding = $this->finding('duplicate_accomplishment', 'review', 'Several bullets open with "Independently". State ownership once, where it is strongest, and let the other bullets show the work.', array_merge([], ...array_map(fn (int $i) => $bullets[$i]['fact_keys'], $own)), []);
            $finding['confidence'] = 'medium';
            $finding['reason'] = 'repeated_ownership';
            $finding['bullets'] = array_map(fn (int $i) => ['role_id' => $bullets[$i]['role_id'], 'index' => $bullets[$i]['index']], $own);
            $finding['drop'] = null;
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Applies the duplicate check to recovered wording. A bullet that adds nothing to an earlier one about the same project
     * (a `high` finding) is removed, with the reason on its review item; every other finding is flagged on the later bullet
     * for a person. A role never loses its only bullet, and nothing is rewritten, so this step cannot create a claim.
     *
     * @param  array{content: array<string, mixed>, items: array<int, array<string, mixed>>}  $recovered  RecoverWording::recover()
     * @param  array<string, array<int, string>>  $keysAt  "role_id:bullet_group_index" => the facts that bullet cites
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<int, string>  $unused  eligible, shareable facts not on the resume, best first
     * @return array{content: array<string, mixed>, items: array<int, array<string, mixed>>, findings: array<int, array<string, mixed>>}
     */
    public function resolveDuplicates(array $recovered, array $keysAt, array $facts, array $unused = []): array
    {
        $bullets = [];
        $textAt = [];

        foreach ((array) $recovered['content']['experience'] as $role) {
            foreach ((array) $role['bullets'] as $bullet) {
                $at = "{$role['role_id']}:{$bullet['bullet_group_index']}";
                $textAt[$at] = (string) $bullet['text'];
                $bullets[] = ['role_id' => (int) $role['role_id'], 'index' => (int) $bullet['bullet_group_index'], 'text' => (string) $bullet['text'], 'fact_keys' => $keysAt[$at] ?? []];
            }
        }

        $content = $recovered['content'];
        $items = $recovered['items'];
        $stored = [];

        foreach ($this->duplicates($bullets, $facts, $unused) as $finding) {
            $drop = $finding['drop'];
            $removed = false;

            if ($drop !== null) {
                foreach ($content['experience'] as $r => $role) {
                    if ((int) $role['role_id'] === $drop['role_id'] && count($role['bullets']) >= 2) {
                        $kept = array_values(array_filter($role['bullets'], fn (array $b) => (int) $b['bullet_group_index'] !== $drop['index']));
                        $removed = count($kept) < count($role['bullets']);
                        $content['experience'][$r]['bullets'] = $kept;
                    }
                }
            }

            $flag = ['kind' => 'duplicate_accomplishment', 'severity' => 'review', 'detail' => $finding['reason'], 'message' => $finding['message']];
            $target = $drop ?? $finding['bullets'][count($finding['bullets']) - 1];

            foreach ($items as $i => $item) {
                if (($item['location'] ?? null) === 'experience' && (int) $item['role_id'] === $target['role_id'] && (int) $item['bullet_group_index'] === $target['index']) {
                    $items[$i] = [
                        ...$item,
                        'findings' => [...$item['findings'], $flag],
                        'status' => $removed ? 'omitted' : ($item['status'] === 'passed' ? 'review' : $item['status']),
                        'text' => $removed ? ($textAt["{$target['role_id']}:{$target['index']}"] ?? $item['text']) : $item['text'],
                        'action' => $removed ? 'Removed: it repeats an earlier bullet and adds no distinct evidence.' : $item['action'],
                    ];
                }
            }

            $stored[] = [...array_diff_key($finding, ['drop' => 1]), 'resolved' => $removed];
        }

        return ['content' => $content, 'items' => $items, 'findings' => $stored];
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, array<string, mixed>>  $facts
     */
    private function projectOf(array $keys, array $facts): ?int
    {
        $counts = array_count_values(array_filter(array_map(fn (string $k) => $facts[$k]['attribution']['project_id'] ?? null, $keys), fn ($p) => $p !== null));
        arsort($counts);

        return $counts === [] ? null : (int) array_key_first($counts);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<string, array<string, mixed>>  $facts
     */
    private function projectNameOf(array $keys, array $facts): string
    {
        foreach ($keys as $key) {
            $name = trim(explode(':', (string) ($facts[$key]['attribution']['project'] ?? ''))[0]);

            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * The words that carry a bullet's content: stems of its longer words, without the project's own name and the verbs
     * and connectives every bullet uses.
     *
     * @return array<int, string>
     */
    private function contentWords(string $text, string $projectName): array
    {
        $skip = array_merge(['built', 'developed', 'designed', 'implemented', 'integrated', 'created', 'delivered', 'owned', 'independently', 'using', 'with', 'from', 'into', 'across', 'their', 'that', 'which', 'while', 'through', 'each', 'than', 'over', 'also', 'platform', 'application', 'applications', 'system', 'systems', 'team', 'teams'], preg_split('/[^a-z0-9]+/', strtolower($projectName)) ?: []);
        $words = array_filter(preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [], fn (string $w) => strlen($w) >= 4 && ! in_array($w, $skip, true));

        return array_values(array_unique(array_map(fn (string $w) => substr($w, 0, 6), $words)));
    }

    /**
     * Measured figures in a text, normalized: "85%", "20+", "10-15", "$1.2". Years and bare small numbers are not results.
     *
     * @return array<int, string>
     */
    private function figures(string $text): array
    {
        // A number that is part of a name is an identifier, not a result: "SHA-256", "AES-256-GCM", "x86", "ES2015", "256-bit".
        // Two bullets that mention different algorithms do not state the same outcome.
        preg_match_all('/(?<![\p{L}\d])(?<![\p{L}]-)(?<![\p{L}])\$?\d[\d,]*+(?:\.\d++)?+\+?%?(?!-?(?:bit|byte|bits|bytes|GCM|CBC|SHA|RSA|AES)\b)/iu', $text, $m);
        $out = [];

        foreach ($m[0] as $token) {
            $n = str_replace(',', '', $token);

            if (preg_match('/^(?:19|20)\d{2}$/', $n) === 1 || (strlen(preg_replace('/\D/', '', $n)) < 2 && ! str_contains($n, '%'))) {
                continue;
            }

            $out[] = $n;
        }

        return array_values(array_unique($out));
    }

    /**
     * Do the selected accomplishments, together, show how the candidate designs, builds, integrates, delivers and what it
     * achieved? A dimension no selected fact shows is reported only when an eligible fact that does is left off the resume,
     * so the finding always names something that could be done. Advice, never a change to the selection.
     *
     * @param  array<int, string>  $selected
     * @param  array<string, array<string, mixed>>  $facts
     * @param  array<int, array<string, mixed>>  $accomplishments
     * @return array<int, array{check: string, severity: string, message: string, selected: array<int, string>, available: array<int, string>}>
     */
    private function storyDimensions(array $selected, array $facts, array $accomplishments): array
    {
        $shown = [];

        foreach ($selected as $key) {
            array_push($shown, ...StoryDimensions::of((string) ($facts[$key]['statement'] ?? '')));
        }

        $findings = [];

        foreach (array_diff(['architecture', 'implementation', 'integration', 'delivery', 'outcome'], array_unique($shown)) as $dimension) {
            $available = $this->available($accomplishments, $selected, fn (string $key) => isset($facts[$key]) && in_array($dimension, StoryDimensions::of((string) $facts[$key]['statement']), true));

            if ($available !== []) {
                $findings[] = $this->finding('story_dimensions', self::INFORMATIONAL, "Nothing selected shows {$dimension}, and approved evidence that does is left off the resume.", [], $available);
            }
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
