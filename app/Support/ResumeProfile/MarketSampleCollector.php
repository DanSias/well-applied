<?php

namespace App\Support\ResumeProfile;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobPostingLifecycleStatus;
use App\Enums\MarketSampleStatus;
use App\Enums\ResumeProfileStatus;
use App\Models\JobPosting;
use App\Models\MarketSample;
use App\Models\MarketSamplePosting;
use App\Models\ResumeProfile;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\FilterDiscoveredCandidates;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\ProviderDiscoveryContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assembles a representative sample of real postings for a Resume Profile.
 *
 * Sources, in order: postings already stored (free), postings an earlier
 * sample retrieved from a provider still inside its refetch window (free),
 * and fresh retrieval through the existing discovery providers (one request
 * per provider, skipped inside the refetch window unless forced). Nothing is
 * ever written to job_postings, so a sampled posting is not an opportunity.
 *
 * Every candidate gets exactly one recorded decision. Rejections run in a
 * fixed order (incomplete, stale, outside the search, not full-stack,
 * duplicate); what survives is chosen newest first under diversity caps per
 * company, provider and primary stack, up to the target size.
 */
final class MarketSampleCollector
{
    public const INCLUDED = 'included';

    public function __construct(
        private readonly SamplingProviders $providers,
        private readonly RequirementExtractor $extractor,
        private readonly FilterDiscoveredCandidates $filter = new FilterDiscoveredCandidates,
    ) {}

    public function collect(ResumeProfile $profile, bool $pilot = false, bool $fetch = true, bool $force = false): MarketSample
    {
        $config = ProfileDefinitions::config($profile->slug);
        $sampleConfig = (array) $config['sample'];
        $target = (int) ($pilot ? $sampleConfig['pilot_target'] : $sampleConfig['target']);

        $sample = MarketSample::create([
            'resume_profile_id' => $profile->id, 'status' => MarketSampleStatus::Collecting, 'pilot' => $pilot,
            'target_size' => $target, 'extraction_version' => (string) config('resume_profiles.extraction_version'),
        ]);

        try {
            $log = [];
            $candidates = [
                ...$this->storedCandidates($profile),
                ...$this->providerCandidates($profile, $sample, $target, $fetch, $force, $log),
            ];

            $decisions = $this->decide($profile, $candidates, $sampleConfig, $target);
            $this->persist($sample, $candidates, $decisions);

            $sample->update([
                'status' => MarketSampleStatus::Ready, 'provider_log' => $log, 'collected_at' => now(),
                'decision_counts' => array_count_values(array_column($decisions, 'decision')),
            ]);
        } catch (Throwable $e) {
            Log::error('Market sample collection failed.', ['sample_id' => $sample->id, 'exception' => $e::class]);
            $sample->update(['status' => MarketSampleStatus::Failed, 'failure' => 'Collection failed unexpectedly.']);

            throw $e;
        }

        // The new sample replaces the previous one as the profile's reference.
        $profile->update(['market_sample_id' => $sample->id, 'revision' => $profile->revision + 1, 'status' => ResumeProfileStatus::Sampling]);

        return $sample->refresh();
    }

    /**
     * @return array<int, SampleCandidate>
     */
    private function storedCandidates(ResumeProfile $profile): array
    {
        return JobPosting::query()
            ->where('career_profile_id', $profile->career_profile_id)
            ->where('status', JobPostingLifecycleStatus::Open->value)
            ->orderByDesc('discovered_at')
            ->get()
            ->map(fn (JobPosting $job) => SampleCandidate::fromStored($job))
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $log
     * @return array<int, SampleCandidate>
     */
    private function providerCandidates(ResumeProfile $profile, MarketSample $sample, int $target, bool $fetch, bool $force, array &$log): array
    {
        $candidates = [];
        $providers = $this->providers->all();
        $window = (int) config('resume_profiles.refetch_hours', 12);

        foreach ((array) config('resume_profiles.providers') as $name) {
            $provider = $providers[$name] ?? null;

            if ($provider === null) {
                continue;
            }

            $last = $this->lastSuccessfulFetch($profile, $name);
            $recent = $last !== null && $last->gt(now()->subHours($window));

            if (! $fetch || ($recent && ! $force)) {
                // Inside the refetch window: reuse what that fetch returned instead of asking again.
                $cached = $this->cachedCandidates($profile, $name, $sample);
                $candidates = [...$candidates, ...$cached];
                $log[] = ['provider' => $name, 'status' => $fetch ? 'reused_recent_fetch' : 'not_requested', 'retrieved' => 0, 'reused' => count($cached), 'at' => now()->toIso8601String(), 'last_fetch' => $last?->toIso8601String()];

                continue;
            }

            try {
                $retrieved = $provider->retrieve(new ProviderDiscoveryContext(ProfileDefinitions::searchProfile($profile), maxResults: max(1, $target * 2)));
                $candidates = [...$candidates, ...array_map(fn ($c) => SampleCandidate::fromDiscovered($c), $retrieved)];
                $log[] = ['provider' => $name, 'status' => 'ok', 'retrieved' => count($retrieved), 'reused' => 0, 'at' => now()->toIso8601String()];
            } catch (DiscoveryProviderException $e) {
                $log[] = ['provider' => $name, 'status' => 'provider_error', 'retrieved' => 0, 'reused' => 0, 'error' => mb_substr($e->getMessage(), 0, 200), 'at' => now()->toIso8601String()];
            } catch (Throwable $e) {
                // An unexpected message can carry credentials or payloads: record the class only.
                $log[] = ['provider' => $name, 'status' => 'unexpected_error', 'retrieved' => 0, 'reused' => 0, 'error' => $e::class, 'at' => now()->toIso8601String()];
            }
        }

        return $candidates;
    }

    private function lastSuccessfulFetch(ResumeProfile $profile, string $provider): ?Carbon
    {
        $latest = null;

        foreach (MarketSample::query()->where('resume_profile_id', $profile->id)->whereNotNull('provider_log')->latest('id')->limit(20)->get() as $sample) {
            foreach ((array) $sample->provider_log as $entry) {
                if (($entry['provider'] ?? null) === $provider && ($entry['status'] ?? null) === 'ok') {
                    $at = Carbon::parse($entry['at']);
                    $latest = $latest === null || $at->gt($latest) ? $at : $latest;
                }
            }
        }

        return $latest;
    }

    /**
     * @return array<int, SampleCandidate>
     */
    private function cachedCandidates(ResumeProfile $profile, string $provider, MarketSample $current): array
    {
        $rows = MarketSamplePosting::query()
            ->whereIn('market_sample_id', MarketSample::query()->where('resume_profile_id', $profile->id)->where('id', '!=', $current->id)->select('id'))
            ->where('provider', $provider)
            ->whereNull('job_posting_id')
            ->whereNotNull('description')
            ->orderByDesc('id')
            ->get()
            ->unique('content_hash');

        return $rows->map(fn (MarketSamplePosting $row) => SampleCandidate::fromPriorRow($row))->values()->all();
    }

    /**
     * One decision per candidate, in candidate order.
     *
     * @param  array<int, SampleCandidate>  $candidates
     * @param  array<string, mixed>  $cfg
     * @return array<int, array{decision: string, detail: string|null, duplicate_of: int|null, stack: string|null}>
     */
    private function decide(ResumeProfile $profile, array $candidates, array $cfg, int $target): array
    {
        $criteria = ProfileDefinitions::criteria($profile);
        $relevance = (array) ProfileDefinitions::config($profile->slug)['relevance'];
        $decisions = [];
        $eligible = [];
        $seenHash = [];
        $seenIdentity = [];
        $seenTitleKey = [];
        $shingles = [];

        foreach ($candidates as $i => $c) {
            $stack = $this->extractor->stack($c->text);
            $reject = function (string $decision, ?string $detail = null, ?int $of = null) use (&$decisions, $i, $stack): void {
                $decisions[$i] = ['decision' => $decision, 'detail' => $detail, 'duplicate_of' => $of, 'stack' => $stack];
            };

            if ($c->completeness !== DescriptionCompleteness::Complete) {
                $reject('incomplete_description', "Description is {$c->completeness->value}, not the full posting.");

                continue;
            }

            if (mb_strlen($c->text) < (int) $cfg['min_description_chars']) {
                $reject('description_too_short', mb_strlen($c->text).' characters.');

                continue;
            }

            if ($c->postedAt !== null && $c->postedAt->lt(now()->subDays((int) $cfg['max_age_days']))) {
                $reject('stale', 'Posted '.$c->postedAt->toDateString().', older than '.$cfg['max_age_days'].' days.');

                continue;
            }

            if ($c->raw === null || ! $this->filter->accepts($c->raw, $criteria)) {
                $reject('outside_search', $this->searchRejection($c->title, $c->text, $criteria));

                continue;
            }

            if (! $this->isFullStack($c, $relevance)) {
                $reject('not_full_stack', 'Title is generic and the description does not ask for both front-end and back-end work.');

                continue;
            }

            $identity = $c->provider.'|'.($c->sourceJobId ?? '');
            $titleKey = PostingText::normalizeCompany($c->company).'|'.PostingText::normalizeTitle($c->title);

            if (isset($seenHash[$c->contentHash])) {
                $reject('duplicate_exact', 'Identical description text.', $seenHash[$c->contentHash]);

                continue;
            }

            if ($c->sourceJobId !== null && isset($seenIdentity[$identity])) {
                $reject('duplicate_exact', 'Same provider posting.', $seenIdentity[$identity]);

                continue;
            }

            if (isset($seenTitleKey[$titleKey])) {
                $reject('duplicate_near', 'Same company and title.', $seenTitleKey[$titleKey]);

                continue;
            }

            $own = PostingText::shingles($c->text);
            $near = null;

            foreach ($shingles as $j => $other) {
                if (PostingText::similarity($own, $other) >= (float) $cfg['near_duplicate_similarity']) {
                    $near = $j;
                    break;
                }
            }

            if ($near !== null) {
                $reject('duplicate_near', 'Description is nearly identical to another posting.', $near);

                continue;
            }

            $seenHash[$c->contentHash] = $i;
            $seenIdentity[$identity] = $i;
            $seenTitleKey[$titleKey] = $i;
            $shingles[$i] = $own;
            $eligible[$i] = $stack;
            $decisions[$i] = ['decision' => 'eligible', 'detail' => null, 'duplicate_of' => null, 'stack' => $stack];
        }

        return $this->select($candidates, $decisions, $eligible, $cfg, $target);
    }

    /**
     * Newest first, under per-company, per-provider and per-stack caps.
     *
     * @param  array<int, SampleCandidate>  $candidates
     * @param  array<int, array{decision: string, detail: string|null, duplicate_of: int|null, stack: string|null}>  $decisions
     * @param  array<int, string|null>  $eligible  candidate index => stack
     * @param  array<string, mixed>  $cfg
     * @return array<int, array{decision: string, detail: string|null, duplicate_of: int|null, stack: string|null}>
     */
    private function select(array $candidates, array $decisions, array $eligible, array $cfg, int $target): array
    {
        $order = array_keys($eligible);
        usort($order, fn (int $a, int $b) => [$candidates[$b]->postedAt?->getTimestamp() ?? 0, $a] <=> [$candidates[$a]->postedAt?->getTimestamp() ?? 0, $b]);

        $companyCap = max(1, (int) $cfg['max_per_company']);
        $providerCap = max(2, (int) ceil($target * (float) $cfg['max_provider_share']));
        $stackCap = max(2, (int) ceil($target * (float) $cfg['max_stack_share']));
        $perCompany = $perProvider = $perStack = [];
        $chosen = 0;

        foreach ($order as $i) {
            $c = $candidates[$i];
            $company = PostingText::normalizeCompany($c->company);
            $stack = $eligible[$i];

            $verdict = match (true) {
                $chosen >= $target => ['over_target', "The sample already holds {$target} postings."],
                ($perCompany[$company] ?? 0) >= $companyCap => ['company_cap', "Already {$companyCap} postings from {$c->company}."],
                ($perProvider[$c->provider] ?? 0) >= $providerCap => ['provider_cap', "Already {$providerCap} postings from {$c->provider}."],
                $stack !== null && ($perStack[$stack] ?? 0) >= $stackCap => ['stack_cap', "Already {$stackCap} postings centred on {$stack}."],
                default => null,
            };

            if ($verdict !== null) {
                $decisions[$i] = [...$decisions[$i], 'decision' => $verdict[0], 'detail' => $verdict[1]];

                continue;
            }

            $perCompany[$company] = ($perCompany[$company] ?? 0) + 1;
            $perProvider[$c->provider] = ($perProvider[$c->provider] ?? 0) + 1;
            $stack !== null && $perStack[$stack] = ($perStack[$stack] ?? 0) + 1;
            $chosen++;
            $decisions[$i] = [...$decisions[$i], 'decision' => self::INCLUDED, 'detail' => null];
        }

        ksort($decisions);

        return $decisions;
    }

    /**
     * @param  array<string, mixed>  $relevance
     */
    private function isFullStack(SampleCandidate $c, array $relevance): bool
    {
        $title = mb_strtolower($c->title);

        foreach ((array) $relevance['title_terms'] as $term) {
            if (str_contains($title, $term)) {
                return true;
            }
        }

        $text = mb_strtolower($c->text);
        $has = fn (array $terms) => array_filter($terms, fn (string $t) => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($t, '/').'(?![\p{L}\p{N}])/u', $text) === 1) !== [];

        return $has((array) $relevance['frontend_terms']) && $has((array) $relevance['backend_terms']);
    }

    private function searchRejection(string $title, string $text, DiscoverySearchCriteria $criteria): string
    {
        $any = function (string $haystack, array $terms): bool {
            foreach ($terms as $term) {
                if (preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($term, '/').'(?![\p{L}\p{N}_])/iu', $haystack) === 1) {
                    return true;
                }
            }

            return false;
        };

        return match (true) {
            ! $any($title, $criteria->keywords) => 'Title is not in the role family.',
            $any($title.' '.$text, $criteria->excludedKeywords) || $any($title, $criteria->excludedTitleKeywords) => 'Excluded role or seniority.',
            default => 'Location or remote status does not fit.',
        };
    }

    /**
     * @param  array<int, SampleCandidate>  $candidates
     * @param  array<int, array{decision: string, detail: string|null, duplicate_of: int|null, stack: string|null}>  $decisions
     */
    private function persist(MarketSample $sample, array $candidates, array $decisions): void
    {
        DB::transaction(function () use ($sample, $candidates, $decisions) {
            $ids = [];

            foreach ($candidates as $i => $c) {
                $d = $decisions[$i];
                $included = $d['decision'] === self::INCLUDED;

                $row = MarketSamplePosting::create([
                    'market_sample_id' => $sample->id, 'job_posting_id' => $c->jobPostingId, 'provider' => $c->provider,
                    'source_job_id' => $c->sourceJobId, 'source_url' => $c->sourceUrl, 'company' => $c->company, 'title' => $c->title,
                    'location' => $c->location, 'remote_status' => $c->remoteStatus, 'primary_stack' => $d['stack'],
                    'description_completeness' => $c->completeness->value, 'description' => $c->text, 'content_hash' => $c->contentHash,
                    'posted_at' => $c->postedAt, 'retrieved_at' => now(), 'included' => $included, 'decision' => $d['decision'],
                    'decision_detail' => $d['detail'], 'duplicate_of_id' => $d['duplicate_of'] === null ? null : ($ids[$d['duplicate_of']] ?? null),
                ]);
                $ids[$i] = $row->id;
            }
        });
    }
}
