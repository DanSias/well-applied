<?php

namespace App\Support\ResumeProfile;

use App\Models\MarketSample;
use App\Models\MarketSamplePosting;

/**
 * Reads every included posting of a sample against the capability taxonomy
 * and rolls the result up: for each capability, how many postings mention it,
 * at what level (required, preferred, duty, mentioned), at which seniority,
 * and which postings show it.
 *
 * Repeatable and cheap: extraction is deterministic and cached by
 * (content hash, extraction version), so re-running on unchanged postings
 * extracts nothing. There is no model call, hence no token usage to record.
 * A capability here is what the MARKET asks for; it says nothing about what
 * the user has done.
 */
final class AggregateMarketRequirements
{
    public function __construct(private readonly RequirementExtractor $extractor) {}

    /**
     * @return array{extracted: int, cached: int, postings: int}
     */
    public function run(MarketSample $sample): array
    {
        $version = (string) config('resume_profiles.extraction_version');
        $stats = ['extracted' => 0, 'cached' => 0, 'postings' => 0];
        $postings = $sample->includedPostings()->orderBy('id')->get();

        foreach ($postings as $posting) {
            $stats['postings']++;

            if ($posting->extraction_version === $version && is_array($posting->extraction)) {
                $stats['cached']++;

                continue;
            }

            $reused = MarketSamplePosting::query()
                ->where('content_hash', $posting->content_hash)
                ->where('extraction_version', $version)
                ->whereNotNull('extraction')
                ->where('id', '!=', $posting->id)
                ->value('extraction');

            if (is_string($reused)) {
                $reused = json_decode($reused, true);
            }

            $extraction = is_array($reused) ? $reused : $this->extractor->extract($posting->title, (string) $posting->description);
            is_array($reused) ? $stats['cached']++ : $stats['extracted']++;

            $posting->update([
                'extraction' => $extraction, 'extraction_version' => $version,
                'seniority' => $extraction['seniority'], 'primary_stack' => $extraction['stack'] ?? $posting->primary_stack,
            ]);
        }

        $sample->update(['extraction_version' => $version, 'aggregate' => $this->aggregate($sample, $postings->fresh()), 'aggregated_at' => now()]);

        return $stats;
    }

    /**
     * @param  iterable<int, MarketSamplePosting>  $postings
     * @return array<string, mixed>
     */
    private function aggregate(MarketSample $sample, iterable $postings): array
    {
        $postings = collect($postings)->values();
        $n = $postings->count();
        $weights = (array) config('resume_profiles.ranking.level_weight');
        $capabilities = (array) config('resume_profiles.capabilities');
        $seniorIds = $postings->filter(fn (MarketSamplePosting $p) => in_array($p->seniority, ['senior', 'staff_plus'], true))->pluck('id')->all();

        $map = [];

        foreach ($capabilities as $key => $capability) {
            $entry = [
                'key' => $key, 'label' => $capability['label'], 'category' => $capability['category'],
                'postings' => 0, 'required' => 0, 'preferred' => 0, 'duty' => 0, 'mentioned' => 0,
                'weight_sum' => 0.0, 'senior_weight_sum' => 0.0, 'senior_postings' => 0,
                'seniority' => [], 'posting_ids' => [], 'examples' => [],
            ];

            foreach ($postings as $posting) {
                $hit = $posting->extraction['capabilities'][$key] ?? null;

                if ($hit === null) {
                    continue;
                }

                $level = $hit['level'];
                $entry['postings']++;
                $entry[$level]++;
                $entry['weight_sum'] += (float) ($weights[$level] ?? 0.5);
                $entry['posting_ids'][] = $posting->id;
                $entry['seniority'][$posting->seniority ?? 'unspecified'] = ($entry['seniority'][$posting->seniority ?? 'unspecified'] ?? 0) + 1;

                if (in_array($posting->id, $seniorIds, true)) {
                    $entry['senior_postings']++;
                    $entry['senior_weight_sum'] += (float) ($weights[$level] ?? 0.5);
                }

                if (count($entry['examples']) < 3) {
                    $entry['examples'][] = ['posting_id' => $posting->id, 'company' => $posting->company, 'title' => $posting->title, 'level' => $level, 'snippet' => $hit['snippet']];
                }
            }

            if ($entry['postings'] === 0) {
                continue;
            }

            $seniorN = count($seniorIds);
            $map[$key] = [
                ...$entry,
                'share' => $n === 0 ? 0.0 : round($entry['postings'] / $n, 4),
                'required_share' => $n === 0 ? 0.0 : round($entry['required'] / $n, 4),
                'preferred_share' => $n === 0 ? 0.0 : round($entry['preferred'] / $n, 4),
                'importance' => $n === 0 ? 0.0 : round($entry['weight_sum'] / $n, 4),
                // Only meaningful with enough senior-level postings to compare.
                'senior_importance' => $seniorN >= 3 ? round($entry['senior_weight_sum'] / $seniorN, 4) : null,
            ];
            unset($map[$key]['weight_sum'], $map[$key]['senior_weight_sum']);
        }

        uasort($map, fn (array $a, array $b) => [$b['importance'], $b['postings']] <=> [$a['importance'], $a['postings']]);

        return [
            'version' => (string) config('resume_profiles.extraction_version'),
            'postings' => $n,
            'senior_postings' => count($seniorIds),
            'providers' => $postings->countBy('provider')->all(),
            'companies' => $postings->pluck('company')->unique()->count(),
            'seniority' => $postings->countBy(fn (MarketSamplePosting $p) => $p->seniority ?? 'unspecified')->all(),
            'newest_posted_at' => $postings->max('posted_at')?->toDateString(),
            'oldest_posted_at' => $postings->min('posted_at')?->toDateString(),
            'capabilities' => $map,
        ];
    }
}
