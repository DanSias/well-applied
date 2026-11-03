<?php

namespace App\Support\ResumeProfile;

use App\Enums\ClarificationSessionStatus;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Enums\ResumeProfileStatus;
use App\Models\CareerFact;
use App\Models\ClarificationQuestion;
use App\Models\ClarificationSession;
use App\Models\EvidenceProposal;
use App\Models\ResumeProfile;
use App\Support\Clarification\EvidenceRevision;
use Illuminate\Support\Collection;

/**
 * The profile discovery interview: ranked, grouped, resumable questions that
 * reuse the clarification tables and the evidence-proposal workflow, so every
 * answer enriches the shared career database and nothing is copied into the
 * profile.
 *
 * One long-lived session per profile. Questions are ranked and stored once
 * and only ever added to or auto-resolved: a question already answered or
 * skipped is never reordered, and one whose capability the record now covers
 * becomes `covered` instead of being asked.
 */
final class ProfileInterview
{
    public function __construct(
        private readonly RankDiscoveryTopics $ranker,
        private readonly CapabilityCoverage $coverage,
    ) {}

    public function session(ResumeProfile $profile): ?ClarificationSession
    {
        return ClarificationSession::query()->where('resume_profile_id', $profile->id)->latest('id')->first();
    }

    /**
     * Creates or updates the interview from the profile's current sample and the
     * current evidence. Idempotent: calling it again adds nothing already asked.
     */
    public function sync(ResumeProfile $profile): ClarificationSession
    {
        $aggregate = $profile->market_sample_id === null ? [] : (array) $profile->currentSample->aggregate;
        $coverage = $this->coverage->compute($profile->careerProfile);
        $max = (int) ProfileDefinitions::config($profile->slug)['interview']['max_topics'];

        $session = $this->session($profile) ?? ClarificationSession::create([
            'career_profile_id' => $profile->career_profile_id, 'resume_profile_id' => $profile->id, 'mode' => 'profile',
            'status' => ClarificationSessionStatus::Pending,
        ]);

        // Resolve what the record now covers, without touching anything answered.
        foreach ($session->questions()->where('status', 'open')->get() as $question) {
            // A follow-up the user asked for stays until it is answered or skipped.
            if (($question->context['source'] ?? null) === 'followup') {
                continue;
            }

            $status = $coverage['capabilities'][$question->topic_key]['status'] ?? null;

            if (in_array($status, [CapabilityCoverage::DIRECT, CapabilityCoverage::LIMITATION], true) || $this->hasApprovedEvidence($profile, $question->topic_key)) {
                $question->update(['status' => 'covered']);
            }
        }

        $ranked = $aggregate === [] ? [] : $this->ranker->rank($profile, $aggregate, $coverage);
        $byKey = [];

        foreach ($ranked as $candidate) {
            $byKey[$candidate['key']] = $candidate;
        }

        // An unanswered question always reflects the CURRENT sample and record: refreshed
        // in place (position kept), or retired when the market no longer makes it a priority.
        foreach ($session->questions()->where('status', 'open')->get() as $question) {
            if (($question->context['source'] ?? null) === 'followup') {
                continue;
            }

            $candidate = $byKey[$question->topic_key] ?? null;

            if ($candidate === null) {
                $question->update(['status' => 'retired']);

                continue;
            }

            $this->refreshQuestion($question, $profile, $candidate, $coverage);
        }

        // A retired question whose topic ranks again is brought back in place.
        foreach ($session->questions()->where('status', 'retired')->get() as $question) {
            if (isset($byKey[$question->topic_key])) {
                $question->update(['status' => 'open']);
                $this->refreshQuestion($question, $profile, $byKey[$question->topic_key], $coverage);
            }
        }

        $existing = $session->questions()->pluck('topic_key')->all();
        $room = $max - $session->questions()->whereNotIn('status', ['retired', 'covered'])->count();
        $next = (int) $session->questions()->max('sort_order') + 1;

        if ($room > 0) {
            foreach ($ranked as $candidate) {
                if ($room <= 0) {
                    break;
                }

                if (in_array($candidate['key'], $existing, true)) {
                    continue;
                }

                $this->createQuestion($session, $profile, $candidate, $coverage, $next++);
                $room--;
            }
        }

        $open = $session->questions()->where('status', 'open')->exists();
        $session->update(['status' => $open ? ClarificationSessionStatus::Pending : ClarificationSessionStatus::Completed, 'resolved_at' => $open ? null : now()]);
        $profile->update([
            'evidence_revision' => $coverage['evidence_revision'],
            'status' => $open ? ResumeProfileStatus::Interviewing : ResumeProfileStatus::Ready,
        ]);

        return $session;
    }

    /**
     * A skipped question can be picked up again later.
     */
    public function reopen(ClarificationQuestion $question): void
    {
        if ($question->status === 'skipped') {
            $question->update(['status' => 'open']);
        }
    }

    public function skip(ClarificationQuestion $question): void
    {
        if ($question->status === 'open') {
            $question->update(['status' => 'skipped']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(ResumeProfile $profile): array
    {
        $session = $this->session($profile);
        $questions = $session?->questions()->get() ?? collect();
        $size = max(1, (int) ProfileDefinitions::config($profile->slug)['interview']['group_size']);
        $counts = $questions->countBy('status');
        $groups = $questions->values()->chunk($size)->map(fn ($chunk, $i) => [
            'index' => $i + 1,
            'question_ids' => $chunk->pluck('id')->all(),
            'done' => $chunk->whereNotIn('status', ['open'])->count(),
            'total' => $chunk->count(),
        ])->values();

        $current = $groups->first(fn (array $g) => $g['done'] < $g['total']);

        return [
            'total' => $questions->count(),
            'answered' => (int) ($counts['answered'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'covered' => (int) ($counts['covered'] ?? 0),
            'open' => (int) ($counts['open'] ?? 0),
            'groups' => $groups->all(),
            'current_group' => $current['index'] ?? null,
            'evidence_changed' => $profile->evidence_revision !== EvidenceRevision::current($profile->careerProfile),
        ];
    }

    /**
     * Facts approved through this profile's interview, newest first.
     *
     * @return Collection<int, EvidenceProposal>
     */
    public function contributedProposals(ResumeProfile $profile)
    {
        return EvidenceProposal::query()
            ->where('career_profile_id', $profile->career_profile_id)
            ->where('kind', ProposalKind::Fact)
            ->where('status', ProposalStatus::Approved)
            ->whereNotNull('career_fact_id')
            ->whereHas('answer.question.session', fn ($q) => $q->where('resume_profile_id', $profile->id))
            ->with(['careerFact', 'answer'])
            ->latest('id')
            ->get();
    }

    private function hasApprovedEvidence(ResumeProfile $profile, string $topic): bool
    {
        return EvidenceProposal::query()->where('career_profile_id', $profile->career_profile_id)->where('topic_key', $topic)
            ->where('status', ProposalStatus::Approved)->whereIn('kind', [ProposalKind::Fact, ProposalKind::Preference])->exists();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $coverage
     */
    private function refreshQuestion(ClarificationQuestion $question, ResumeProfile $profile, array $candidate, array $coverage): void
    {
        $attributes = $this->questionAttributes($profile, $candidate, $coverage);
        $question->update(array_intersect_key($attributes, array_flip(['question_text', 'score', 'score_breakdown', 'context'])));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $coverage
     */
    private function createQuestion(ClarificationSession $session, ResumeProfile $profile, array $candidate, array $coverage, int $order): void
    {
        $session->questions()->create([...$this->questionAttributes($profile, $candidate, $coverage), 'status' => 'open', 'sort_order' => $order]);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $coverage
     * @return array<string, mixed>
     */
    private function questionAttributes(ResumeProfile $profile, array $candidate, array $coverage): array
    {
        $key = $candidate['key'];
        $capability = (array) config("resume_profiles.capabilities.{$key}");
        $market = $candidate['market'];
        $facts = (array) ($coverage['capabilities'][$key]['facts'] ?? []);
        $refs = (array) ($coverage['capabilities'][$key]['refs'] ?? []);
        [$type, $id, $visibility] = $this->suggestAttribution($facts);

        return [
            'topic_key' => $key,
            'topic_label' => $capability['label'],
            'related_finding_ids' => [],
            'question_text' => $this->text($profile, $capability, $market, $candidate['status'], $candidate['kind'], $facts, $refs, (int) $candidate['postings_total'], (array) ($coverage['notable'] ?? [])),
            'score' => $candidate['score'],
            'score_breakdown' => $candidate['breakdown'],
            'context' => [
                'source' => 'market',
                'kind' => $candidate['kind'],
                'depth' => $candidate['depth'],
                'refs' => $refs,
                'coverage' => $candidate['status'],
                'requirement' => $capability['label'],
                'strength' => $market['required'] > 0 ? 'required' : 'preferred',
                'emphasis' => null,
                'related_evidence' => array_map(fn (array $f) => $f + ['relationship' => 'related'], $facts),
                'market' => [
                    'postings' => $market['postings'], 'sample_postings' => $candidate['postings_total'], 'share' => $market['share'],
                    'required_share' => $market['required_share'], 'preferred_share' => $market['preferred_share'], 'examples' => $market['examples'],
                ],
            ],
            'suggested_attributable_type' => $type,
            'suggested_attributable_id' => $id,
            'suggested_visibility' => $visibility,
        ];
    }

    /**
     * Anchor an answer on the role of related evidence when there is some, with the most
     * restrictive visibility among it; otherwise leave the attribution to the user.
     *
     * @param  array<int, array<string, mixed>>  $facts
     * @return array{0: string|null, 1: int|null, 2: string}
     */
    private function suggestAttribution(array $facts): array
    {
        if ($facts === []) {
            return [null, null, 'restricted'];
        }

        $models = CareerFact::query()->whereIn('key', array_column($facts, 'key'))->get();
        $rank = ['public' => 0, 'restricted' => 1, 'private' => 2];
        $first = $models->first();
        $visibility = $models->map(fn (CareerFact $f) => $f->visibility->value)->sortByDesc(fn (string $v) => $rank[$v])->first() ?? 'restricted';

        return [$first?->attributable_type, $first?->attributable_id, $visibility === 'private' ? 'restricted' : $visibility];
    }

    /**
     * Two kinds of question. Discovery: the capability is absent or only related, so ask whether
     * the user has done it. Deepening: the user clearly has it (a Skill, a thin fact), so ask for
     * the substantial example, what they personally did and the outcome, naming their real roles
     * and projects, because that is what becomes a strong resume accomplishment.
     *
     * @param  array<string, mixed>  $capability
     * @param  array<string, mixed>  $market
     * @param  array<int, array<string, mixed>>  $facts
     * @param  array<int, array<string, mixed>>  $refs
     * @param  array<int, array<string, mixed>>  $notable  the user's best-documented projects, used when nothing closer is known
     */
    private function text(ResumeProfile $profile, array $capability, array $market, string $status, string $kind, array $facts, array $refs, int $total, array $notable = []): string
    {
        $label = (string) $capability['label'];
        $pct = (int) round($market['share'] * 100);
        $req = (int) round($market['required_share'] * 100);
        $demand = "{$market['postings']} of {$total} {$profile->name} postings ({$pct}%) ask for {$label}".($req > 0 ? ", {$req}% as a stated requirement." : '.');
        $where = $this->names(array_column($refs, 'label'));

        if ($kind === 'deepening') {
            $known = match (true) {
                $where === '' => "Your record shows {$label} but says little about what you did with it.",
                $status === CapabilityCoverage::UNDERDOCUMENTED => "Your record lists {$label} on {$where}, but says little about what you actually did with it.",
                default => "Your record mentions {$label} on {$where}".($facts === [] ? '.' : ': "'.mb_strimwidth($facts[0]['statement'], 0, 110, '...').'"'),
            };

            return "{$demand} {$known} Which of your applications involved the most substantial {$label} work? What did you personally design or implement, what technical problems did you solve, and is there a result you can quantify?";
        }

        $subject = (string) ($capability['ask'] ?? $label);

        if ($status === CapabilityCoverage::TRANSFERABLE && $where !== '') {
            return "{$demand} Your record has related work on {$where}, but nothing that says this directly. Did any of that include {$subject}? If so, what was the situation and what did you personally do?";
        }

        // Architecture and responsibility topics are about what you did on real work: anchor on real projects.
        if (in_array($capability['category'], ['architecture', 'responsibility'], true) && $notable !== []) {
            $projects = $this->names(array_column($notable, 'label'));

            return "{$demand} Nothing in your record covers this directly. Thinking of work such as {$projects}: did you do {$subject} on any of it, or elsewhere? If so, what was the situation and what did you personally decide or build?";
        }

        return "{$demand} Nothing in your record covers this yet. Have you done anything like {$subject}, at a job, for a client, or on your own? If so, what was the situation and what did you personally do?";
    }

    /**
     * @param  array<int, string>  $names
     */
    private function names(array $names): string
    {
        $names = array_values(array_unique($names));

        return match (count($names)) {
            0 => '',
            1 => $names[0],
            2 => "{$names[0]} and {$names[1]}",
            default => $names[0].', '.$names[1].' and '.$names[2],
        };
    }
}
