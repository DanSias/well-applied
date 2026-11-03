<?php

namespace App\Support\ResumeProfile;

use App\Enums\MarketSampleStatus;
use App\Enums\ResponsibilityScope;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\MarketSample;
use App\Models\PresentationPreference;
use App\Models\ResumeProfile;
use App\Support\Clarification\PresentApprovedEvidence;
use App\Support\Clarification\PresentClarification;
use Illuminate\Support\Collection;

/**
 * Everything the Resume Profile page needs, derived from the database on
 * each request, so leaving and coming back shows the same progress.
 */
final class PresentResumeProfile
{
    private const DECISION_LABELS = [
        'incomplete_description' => 'Only a description preview',
        'description_too_short' => 'Description too short',
        'stale' => 'Too old',
        'outside_search' => 'Outside the role family or location',
        'not_full_stack' => 'Not a full-stack role',
        'duplicate_exact' => 'Duplicate',
        'duplicate_near' => 'Near-duplicate',
        'company_cap' => 'Too many from one company',
        'provider_cap' => 'Too many from one provider',
        'stack_cap' => 'Too many on one stack',
        'over_target' => 'Sample was already full',
    ];

    private const STATUS_LABELS = [
        CapabilityCoverage::DIRECT => 'Documented',
        CapabilityCoverage::TRANSFERABLE => 'Related experience',
        CapabilityCoverage::UNDERDOCUMENTED => 'Thinly documented',
        CapabilityCoverage::LIMITATION => 'No direct experience',
        CapabilityCoverage::NONE => 'Not yet documented',
    ];

    public function __construct(
        private readonly CapabilityCoverage $coverage,
        private readonly ProfileInterview $interview,
        private readonly PresentClarification $clarification,
        private readonly PresentApprovedEvidence $approved,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ResumeProfile $profile): array
    {
        $sample = $profile->currentSample;
        $coverage = $this->coverage->compute($profile->careerProfile);
        $building = $this->isBuilding($profile);
        $progress = $this->interview->progress($profile);
        $session = $this->interview->session($profile);

        return [
            'profile' => [
                'slug' => $profile->slug, 'name' => $profile->name, 'purpose' => $profile->purpose,
                'status' => $profile->status->value, 'status_label' => $profile->status->label(), 'revision' => $profile->revision,
            ],
            'build' => [
                'state' => $building ? 'building' : ($sample === null ? ($this->lastFailed($profile) ? 'failed' : 'none') : 'ready'),
                'label' => $sample === null ? 'Build My Full-Stack Profile' : 'Refresh the market study',
                'pilot_available' => true,
            ],
            'sample' => $sample === null || $sample->status !== MarketSampleStatus::Ready ? null : $this->sample($sample),
            'market' => $sample?->aggregate === null ? [] : $this->market($sample, $coverage),
            'coverage' => ['counts' => $coverage['counts'], 'evidence_changed' => $progress['evidence_changed']],
            'interview' => [
                'progress' => $progress,
                'session_id' => $session?->id,
                'questions' => $session === null ? [] : $this->questions($profile, $session->questions()->get()),
                'attribution_options' => $this->clarification->attributionOptions($profile->career_profile_id),
                'scopes' => collect(ResponsibilityScope::cases())->reject(fn (ResponsibilityScope $s) => $s === ResponsibilityScope::NoExperience)
                    ->map(fn (ResponsibilityScope $s) => ['value' => $s->value, 'label' => $s->label()])->values()->all(),
                'limitations' => CareerLimitation::query()->where('career_profile_id', $profile->career_profile_id)->active()->orderBy('topic')->get()
                    ->map(fn (CareerLimitation $l) => ['id' => $l->id, 'topic' => $l->topic, 'status' => $l->status, 'confirmed_at' => $l->confirmed_at->toDateString()])->all(),
                'preferences' => PresentationPreference::query()->where('career_profile_id', $profile->career_profile_id)->active()->orderBy('topic')->get()
                    ->map(fn (PresentationPreference $p) => ['id' => $p->id, 'topic' => $p->topic, 'guidance' => $p->guidance])->all(),
            ],
            'contributed' => $this->approved->presentProposals($profile->career_profile_id, $this->interview->contributedProposals($profile)),
        ];
    }

    private function isBuilding(ResumeProfile $profile): bool
    {
        return MarketSample::query()->where('resume_profile_id', $profile->id)->where('status', MarketSampleStatus::Collecting)
            ->where('created_at', '>=', now()->subMinutes(15))->exists();
    }

    private function lastFailed(ResumeProfile $profile): bool
    {
        return MarketSample::query()->where('resume_profile_id', $profile->id)->latest('id')->value('status') === MarketSampleStatus::Failed->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(MarketSample $sample): array
    {
        $aggregate = (array) $sample->aggregate;
        $excluded = $sample->postings()->where('included', false)->get();

        return [
            'size' => (int) ($aggregate['postings'] ?? 0),
            'target' => $sample->target_size,
            'pilot' => $sample->pilot,
            'collected_at' => $sample->collected_at?->toIso8601String(),
            'age_days' => $sample->collected_at === null ? null : (int) $sample->collected_at->diffInDays(now()),
            'companies' => (int) ($aggregate['companies'] ?? 0),
            'providers' => $aggregate['providers'] ?? [],
            'seniority' => $aggregate['seniority'] ?? [],
            'posted_between' => [$aggregate['oldest_posted_at'] ?? null, $aggregate['newest_posted_at'] ?? null],
            'considered' => $sample->postings()->count(),
            'exclusions' => collect($sample->decision_counts ?? [])->except('included')
                ->map(fn (int $count, string $reason) => ['reason' => $reason, 'label' => self::DECISION_LABELS[$reason] ?? $reason, 'count' => $count])->values()->all(),
            'provider_log' => array_map(fn (array $e) => ['provider' => $e['provider'], 'status' => $e['status'], 'retrieved' => $e['retrieved'] ?? 0, 'reused' => $e['reused'] ?? 0], (array) $sample->provider_log),
            'postings' => $sample->includedPostings()->orderByDesc('posted_at')->get(['id', 'company', 'title', 'provider', 'source_url', 'posted_at', 'seniority'])
                ->map(fn ($p) => ['id' => $p->id, 'company' => $p->company, 'title' => $p->title, 'provider' => $p->provider, 'source_url' => $p->source_url, 'posted_at' => $p->posted_at?->toDateString(), 'seniority' => $p->seniority])->all(),
            'excluded_examples' => $excluded->take(20)->map(fn ($p) => ['company' => $p->company, 'title' => $p->title, 'provider' => $p->provider, 'decision' => self::DECISION_LABELS[$p->decision] ?? $p->decision, 'detail' => $p->decision_detail])->all(),
        ];
    }

    /**
     * Capabilities grouped by category, most important first, each with its coverage.
     *
     * @param  array<string, mixed>  $coverage
     * @return array<int, array<string, mixed>>
     */
    private function market(MarketSample $sample, array $coverage): array
    {
        $labels = ['technology' => 'Technologies and frameworks', 'practice' => 'Engineering practices', 'architecture' => 'Architecture and integration patterns', 'responsibility' => 'Responsibilities and ownership', 'collaboration' => 'Collaboration and delivery', 'domain' => 'Domain experience'];
        $groups = [];

        foreach ((array) ($sample->aggregate['capabilities'] ?? []) as $key => $m) {
            $status = $coverage['capabilities'][$key]['status'] ?? CapabilityCoverage::NONE;
            $groups[$m['category']][] = [
                'key' => $key, 'label' => $m['label'], 'postings' => $m['postings'], 'share' => $m['share'],
                'required_share' => $m['required_share'], 'preferred_share' => $m['preferred_share'],
                'coverage' => $status, 'coverage_label' => self::STATUS_LABELS[$status],
                'examples' => array_map(fn (array $e) => ['company' => $e['company'], 'title' => $e['title'], 'level' => $e['level']], array_slice($m['examples'], 0, 2)),
            ];
        }

        $out = [];

        foreach ($labels as $category => $label) {
            if (isset($groups[$category])) {
                $out[] = ['category' => $category, 'label' => $label, 'capabilities' => $groups[$category]];
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, ClarificationQuestion>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function questions(ResumeProfile $profile, $questions): array
    {
        $options = $this->clarification->attributionOptions($profile->career_profile_id);
        $size = max(1, (int) ProfileDefinitions::config($profile->slug)['interview']['group_size']);

        return $questions->values()->map(fn (ClarificationQuestion $q, int $i) => [
            ...$this->clarification->question($q, $options),
            'group' => intdiv($i, $size) + 1,
        ])->all();
    }
}
