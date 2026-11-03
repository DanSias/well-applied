<?php

namespace App\Support\Expansion;

use App\Enums\ProposalStatus;
use App\Models\EvidenceProposal;
use App\Support\Clarification\PresentClarification;
use App\Support\Clarification\ReviewEvidenceProposal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review operations on a claim group: edit, approve, reject, merge. Every operation is per
 * proposal underneath, through the existing ReviewEvidenceProposal rules (explicit visibility,
 * scope guard, duplicate and same-topic refusal, evidence revision), so nothing here can approve
 * what a single proposal could not. Only still-`proposed` members are ever touched; approved facts
 * and their history are never changed except through an explicit "replace" (supersede).
 */
final class ExpandedClaims
{
    public function __construct(private readonly ReviewEvidenceProposal $review, private readonly PresentClarification $options) {}

    /**
     * @param  array<string, mixed>  $fields  statement, scopes, verification, visibility, attributions (list of "type:id")
     */
    public function update(string $group, array $fields): void
    {
        DB::transaction(function () use ($group, $fields) {
            $members = $this->open($group);
            $plain = array_intersect_key($fields, array_flip(['statement', 'scopes', 'verification', 'visibility']));

            if ($plain !== []) {
                foreach ($members as $member) {
                    $this->review->update($member, $plain);
                }
            }

            if (array_key_exists('attributions', $fields)) {
                $this->syncAttributions($group, $members, array_values(array_unique((array) $fields['attributions'])));
            }
        });
    }

    /**
     * @param  array<int, int>  $supersede  proposal ids whose existing same-topic fact may be replaced
     * @param  array<int|string, string>  $visibilities  optional proposal id => visibility overriding $visibility
     */
    public function approve(string $group, ?string $visibility, array $supersede = [], array $visibilities = [], bool $acknowledgeMetric = false): void
    {
        $members = $this->open($group);

        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['claim' => 'Nothing in this claim is waiting for approval.']);
        }

        $labels = $this->labels((int) $members->first()->career_profile_id);

        DB::transaction(function () use ($members, $visibility, $supersede, $visibilities, $acknowledgeMetric, $labels) {
            foreach ($members as $member) {
                $chosen = $visibilities[$member->id] ?? $visibility;
                $where = $labels["{$member->attributable_type}:{$member->attributable_id}"] ?? 'this place';

                try {
                    $this->review->approve($member, $chosen === null ? [] : ['visibility' => $chosen], [
                        'supersede' => in_array($member->id, $supersede, true), 'acknowledge_metric' => $acknowledgeMetric,
                    ]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['claim' => "{$where}: ".implode(' ', array_merge(...array_values($e->errors())))]);
                }
            }
        });
    }

    public function reject(string $group): void
    {
        foreach ($this->open($group) as $member) {
            // No question side effects: the answer's other proposals are unaffected.
            $member->fill(['status' => ProposalStatus::Rejected, 'decided_at' => now()]);
            $member->recordHistory('rejected');
            $member->save();
        }
    }

    /**
     * Folds `$group` into `$into`: places not already in the target claim move over (taking its
     * wording and scope), places it already covers are dropped.
     */
    public function merge(string $group, string $into): void
    {
        if ($group === $into) {
            throw ValidationException::withMessages(['claim' => 'Choose a different claim to merge into.']);
        }

        DB::transaction(function () use ($group, $into) {
            $target = $this->open($into);
            $source = $this->open($group);

            if ($target->isEmpty() || $source->isEmpty()) {
                throw ValidationException::withMessages(['claim' => 'Both claims must still be waiting for approval.']);
            }

            $present = $target->map(fn (EvidenceProposal $p) => "{$p->attributable_type}:{$p->attributable_id}")->all();
            $lead = $target->first();

            foreach ($source as $member) {
                $key = "{$member->attributable_type}:{$member->attributable_id}";

                if (in_array($key, $present, true)) {
                    $member->fill(['status' => ProposalStatus::Rejected, 'decided_at' => now()]);
                    $member->recordHistory('merged_duplicate', ['into_group' => $into]);
                } else {
                    $member->fill([
                        'claim_group' => $into, 'topic_key' => $lead->topic_key, 'topic_label' => $lead->topic_label,
                        'statement' => $lead->statement, 'scopes' => $lead->scopes, 'visibility' => $lead->visibility,
                    ]);
                    $member->recordHistory('merged', ['from_group' => $group, 'into_group' => $into]);
                    $present[] = $key;
                }

                $member->save();
            }
        });
    }

    /**
     * @return Collection<int, EvidenceProposal>
     */
    private function open(string $group): Collection
    {
        return EvidenceProposal::query()->where('claim_group', $group)->where('status', ProposalStatus::Proposed)->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, EvidenceProposal>  $members
     * @param  array<int, string>  $desired
     */
    private function syncAttributions(string $group, Collection $members, array $desired): void
    {
        $template = $members->first() ?? EvidenceProposal::query()->where('claim_group', $group)->latest('id')->firstOrFail();
        $labels = $this->labels((int) $template->career_profile_id);
        $desired = array_values(array_filter($desired, fn (string $t) => isset($labels[$t]) && preg_match('/^(role|project):\d+$/', $t) === 1));

        if ($desired === []) {
            throw ValidationException::withMessages(['attributions' => 'A claim needs at least one project or role.']);
        }

        $have = [];

        foreach ($members as $member) {
            $key = "{$member->attributable_type}:{$member->attributable_id}";

            if (in_array($key, $desired, true)) {
                $have[] = $key;

                continue;
            }

            $member->fill(['status' => ProposalStatus::Rejected, 'decided_at' => now()]);
            $member->recordHistory('removed_from_claim');
            $member->save();
        }

        foreach (array_diff($desired, $have) as $key) {
            [$type, $id] = explode(':', $key);
            $copy = $template->replicate(['id', 'career_fact_id', 'decided_at', 'history', 'created_at', 'updated_at']);
            $copy->fill(['status' => ProposalStatus::Proposed, 'attributable_type' => $type, 'attributable_id' => (int) $id, 'career_fact_id' => null, 'history' => []]);
            $copy->recordHistory('added_to_claim', ['from_group' => $group]);
            $copy->save();
        }
    }

    /**
     * @return array<string, string>
     */
    private function labels(int $careerProfileId): array
    {
        $out = [];

        foreach ($this->options->attributionOptions($careerProfileId) as $o) {
            $out["{$o['type']}:{$o['id']}"] = $o['label'];
        }

        return $out;
    }
}
