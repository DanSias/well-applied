<?php

namespace App\Support\JobTailoring;

/**
 * Turns the approved profile into the starting point for one job, without a second selection engine. The result has the
 * shape of EffectiveProfile::resolve(), so ReferenceSelection (ranking, claim-group collapse, per-role allocation,
 * novelty, skills) runs on it unchanged.
 *
 * What the job changes: the order and score of accomplishments (job relevance leads, the profile's own scoring stays as a
 * prior), the order of the user's non-pinned projects, the weight of capabilities (which drives the skills list), and
 * which facts the summary draws on. What it never changes: a user's exclusions (an excluded item or project stays out),
 * pins, and the facts themselves. A relevant approved fact the reference resume did not emphasize is simply ranked up
 * from the approved record, so nothing the job needs is limited to what the profile highlights.
 */
final class ProfileJobAdapter
{
    /** The profile's own score is a prior; job relevance decides what leads. */
    private const PRIOR = 0.5;

    private const SUMMARY_FROM_JOB = 5;

    private const BULLET_RELEVANCE = 0.5;

    private const PRIORITY_FINDINGS = 5;

    /**
     * @param  array<string, mixed>  $effective
     * @param  array<string, mixed>  $relevance  JobRelevance::analyze()
     * @return array<string, mixed>
     */
    public function adapt(array $effective, array $relevance): array
    {
        $facts = $relevance['facts'];

        $accomplishments = array_map(function (array $a) use ($facts) {
            $score = self::PRIOR * (float) $a['score'] + (float) ($facts[$a['fact_key']]['score'] ?? 0.0);

            $relevant = (float) ($facts[$a['fact_key']]['score'] ?? 0.0);
            // "Worked with merchant integrations involving webhooks" is a technology mention to a general resume, but it is
            // the experience a job that asks for webhooks wants: strong relevance makes it a bullet candidate, not skills text.
            $kind = $a['kind'] === 'platform_use' && $relevant >= self::BULLET_RELEVANCE ? 'implementation' : $a['kind'];

            return [...$a, 'kind' => $kind, 'profile_score' => $a['score'], 'score' => round($score, 3), 'job_relevance' => $relevant, 'job_findings' => $facts[$a['fact_key']]['finding_ids'] ?? []];
        }, $effective['accomplishments']);
        usort($accomplishments, fn (array $x, array $y) => $y['score'] <=> $x['score']);

        foreach ($accomplishments as $i => &$a) {
            $a['rank'] = $i + 1;
        }
        unset($a);

        $projects = array_map(function (array $p) use ($facts) {
            $best = max([0.0, ...array_map(fn (string $k) => (float) ($facts[$k]['score'] ?? 0.0), $p['fact_keys'])]);

            return [...$p, 'job_relevance' => round($best, 3)];
        }, $effective['projects']);
        // The user's pins and order hold; the rest of the projects follow the job, then the profile's own rank.
        usort($projects, fn (array $x, array $y) => [$x['pinned'] ? 0 : 1, $x['order'] ?? PHP_INT_MAX, -$x['job_relevance'], $x['rank'] ?? PHP_INT_MAX] <=> [$y['pinned'] ? 0 : 1, $y['order'] ?? PHP_INT_MAX, -$y['job_relevance'], $y['rank'] ?? PHP_INT_MAX]);

        $capabilities = $effective['capabilities'];
        $capabilities['direct'] = array_map(fn (array $c) => [...$c, 'market_share' => (float) ($relevance['capabilities'][$c['key']] ?? 0.0)], $capabilities['direct']);

        $effective['accomplishments'] = $accomplishments;
        $effective['projects'] = $projects;
        $effective['capabilities'] = $capabilities;
        $effective['presentation']['summary_fact_keys'] = $this->summaryKeys($effective, $relevance);
        $effective['job_priority_findings'] = $this->priorityFindings($accomplishments, $relevance);

        return $effective;
    }

    /**
     * The job's strongest requirements that approved, included evidence speaks to: each deserves a bullet.
     *
     * @param  array<int, array<string, mixed>>  $accomplishments
     * @param  array<string, mixed>  $relevance
     * @return array<int, int>
     */
    private function priorityFindings(array $accomplishments, array $relevance): array
    {
        $covered = [];

        foreach ($accomplishments as $a) {
            if ($a['included']) {
                foreach ($a['job_findings'] as $id) {
                    $covered[$id] = true;
                }
            }
        }

        $findings = array_filter($relevance['findings'], fn (array $f) => isset($covered[$f['id']]) && in_array($f['category'], ['required_qualification', 'responsibility'], true));
        uasort($findings, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return array_slice(array_keys($findings), 0, self::PRIORITY_FINDINGS);
    }

    /**
     * The facts the summary may draw on: the two the approved summary leads with (the profile's identity), then the
     * facts that speak most to the job, including a support-only fact when the job asks for what it authorizes.
     *
     * @param  array<string, mixed>  $effective
     * @param  array<string, mixed>  $relevance
     * @return array<int, string>
     */
    private function summaryKeys(array $effective, array $relevance): array
    {
        $keys = array_slice($effective['presentation']['summary_fact_keys'], 0, 2);
        $ranked = $relevance['facts'];
        uasort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $excluded = array_column(array_filter($effective['accomplishments'], fn (array $a) => ! $a['included']), 'fact_key');

        foreach (array_keys($ranked) as $key) {
            if (count($keys) >= 2 + self::SUMMARY_FROM_JOB) {
                break;
            }

            if (! in_array($key, $keys, true) && ! in_array($key, $excluded, true) && isset($effective['facts'][$key])) {
                $keys[] = $key;
            }
        }

        // A first-person fact (support-only) authorizes a capability the job asks for; its wording is never used.
        $added = 0;

        foreach ($effective['facts'] as $key => $fact) {
            $asked = array_filter((array) $fact['capabilities'], fn (string $c) => ($relevance['capabilities'][$c] ?? 0.0) >= 0.3);

            if (($fact['support_only'] ?? false) && $asked !== [] && ! in_array($key, $keys, true) && $added < 2) {
                $keys[] = $key;
                $added++;
            }
        }

        return $keys;
    }
}
