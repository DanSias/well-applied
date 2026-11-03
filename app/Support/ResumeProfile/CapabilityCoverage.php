<?php

namespace App\Support\ResumeProfile;

use App\Enums\Verification;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\CareerProfile;
use App\Models\Project;
use App\Models\Role;
use App\Support\Clarification\EvidenceRevision;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\ResumeVariant\ResumeEligibility;
use Illuminate\Support\Collection;

/**
 * Compares market capabilities with the user's canonical evidence. It never
 * infers that a market requirement is something the user has done; it only
 * reports what the approved record already says.
 *
 *   directly_supported  an eligible fact states it (not merely "needs confirmation")
 *   transferable        no direct statement, but a closely related capability is supported
 *   underdocumented     only a Skill tag or an unconfirmed fact mentions it
 *   limitation          the user confirmed no direct experience
 *   no_evidence         nothing in the eligible record
 *
 * Private facts are not eligible evidence: they cannot count towards coverage
 * and their wording never appears in the result. Deterministic; no model call.
 */
final class CapabilityCoverage
{
    public const DIRECT = 'directly_supported';

    public const TRANSFERABLE = 'transferable';

    public const UNDERDOCUMENTED = 'underdocumented';

    public const LIMITATION = 'limitation';

    public const NONE = 'no_evidence';

    /**
     * @return array{evidence_revision: int, notable: array<int, array{type: string, id: int, label: string}>, capabilities: array<string, array<string, mixed>>, counts: array<string, int>}
     */
    public function compute(CareerProfile $profile): array
    {
        $capabilities = (array) config('resume_profiles.capabilities');
        $facts = ResumeEligibility::eligibleCareerFactsQuery($profile)->with([
            'skills', 'metric',
            'attributable' => fn ($relation) => $relation->morphWith([Role::class => ['employer'], Project::class => ['role.employer']]),
        ])->get();
        $limited = CareerLimitation::query()->where('career_profile_id', $profile->id)->active()->pluck('topic_key')->all();

        $own = [];

        foreach ($capabilities as $key => $capability) {
            $own[$key] = ['solid' => [], 'weak' => [], 'skill_only' => []];

            foreach ($facts as $fact) {
                if ($this->anyMatch($capability['patterns'], $fact->statement)) {
                    $bucket = $fact->verification === Verification::NeedsConfirmation ? 'weak' : 'solid';
                    $own[$key][$bucket][] = $fact;
                } elseif ($this->anyMatch($capability['patterns'], $fact->skills->pluck('name')->implode(' | '))) {
                    $own[$key]['skill_only'][] = $fact;
                }
            }
        }

        // Evidence for a child capability (a webhook handler) is evidence for its parent (webhooks).
        foreach ($capabilities as $key => $capability) {
            $parent = $capability['parent'] ?? null;

            if ($parent !== null && isset($own[$parent])) {
                $own[$parent]['solid'] = $this->merge($own[$parent]['solid'], $own[$key]['solid']);
                $own[$parent]['weak'] = $this->merge($own[$parent]['weak'], $own[$key]['weak']);
            }
        }

        $result = [];

        foreach ($capabilities as $key => $capability) {
            $solid = $own[$key]['solid'];
            $soft = $this->merge($own[$key]['weak'], $own[$key]['skill_only']);
            $related = [];

            foreach ((array) ($capability['related'] ?? []) as $relatedKey) {
                $related = $this->merge($related, $own[$relatedKey]['solid'] ?? []);
            }

            [$status, $facts4] = match (true) {
                in_array($key, $limited, true) => [self::LIMITATION, []],
                $solid !== [] => [self::DIRECT, $solid],
                $soft !== [] => [self::UNDERDOCUMENTED, $soft],
                $related !== [] => [self::TRANSFERABLE, $related],
                default => [self::NONE, []],
            };

            $result[$key] = [
                'key' => $key, 'label' => $capability['label'], 'category' => $capability['category'], 'status' => $status,
                'facts' => array_map(fn (CareerFact $f) => ['key' => $f->key, 'statement' => $f->statement, 'visibility' => $f->visibility->value], array_slice($facts4, 0, 4)),
                // The real roles and projects behind the evidence, so questions can name them.
                'refs' => $this->refs($facts4),
                'depth' => $this->depth($status, $solid),
            ];
        }

        return [
            'evidence_revision' => EvidenceRevision::current($profile),
            'notable' => $this->notable($facts),
            'capabilities' => $result,
            'counts' => array_count_values(array_column($result, 'status')),
        ];
    }

    /**
     * The projects with the most eligible evidence: real places to anchor a question when nothing
     * more specific is known.
     *
     * @param  Collection<int, CareerFact>  $facts
     * @return array<int, array{type: string, id: int, label: string}>
     */
    private function notable($facts): array
    {
        $ranked = $facts->filter(fn (CareerFact $f) => $f->attributable_type === 'project')->groupBy('attributable_id')->sortByDesc(fn ($group) => $group->count())->take(3);

        return $ranked->map(fn ($group) => $this->refs([$group->first()])[0] ?? null)->filter()->values()->all();
    }

    /**
     * How well a DIRECTLY supported capability is documented: `deep` (three or more facts across
     * two or more roles or projects), `moderate`, or `shallow` (one fact, no measured outcome).
     * Other statuses have no depth to speak of.
     *
     * @param  array<int, CareerFact>  $solid
     * @return array{level: string|null, facts: int, attributions: int, has_metric: bool}
     */
    private function depth(string $status, array $solid): array
    {
        $attributions = count(array_unique(array_map(fn (CareerFact $f) => $f->attributable_type.':'.$f->attributable_id, $solid)));
        $metric = array_filter($solid, fn (CareerFact $f) => $f->metric !== null) !== [];
        // One claim attached to several places is one piece of evidence, not several.
        $claims = count(array_unique(array_map(fn (CareerFact $f) => $f->claim_group ?? $f->key, $solid)));
        $level = match (true) {
            $status !== self::DIRECT => null,
            $claims >= 3 && $attributions >= 2 => 'deep',
            $claims >= 2 || $metric => 'moderate',
            default => 'shallow',
        };

        return ['level' => $level, 'facts' => $claims, 'attributions' => $attributions, 'has_metric' => $metric];
    }

    /**
     * @param  array<int, CareerFact>  $facts
     * @return array<int, array{type: string, id: int, label: string}>
     */
    private function refs(array $facts): array
    {
        $refs = [];

        foreach ($facts as $fact) {
            $id = $fact->attributable_id;
            $type = $fact->attributable_type;

            if (isset($refs[$type.':'.$id]) || count($refs) >= 3) {
                continue;
            }

            $a = CareerFactAttribution::resolve($fact);
            $label = match (true) {
                $a->project !== null => $a->project.($a->employer !== null ? " ({$a->employer})" : ''),
                $a->role !== null => $a->role.($a->employer !== null ? " at {$a->employer}" : ''),
                $a->employer !== null => $a->employer,
                default => null,
            };

            if ($label !== null) {
                $refs[$type.':'.$id] = ['type' => $type, 'id' => (int) $id, 'label' => $label];
            }
        }

        return array_values($refs);
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function anyMatch(array $patterns, string $text): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, CareerFact>  $a
     * @param  array<int, CareerFact>  $b
     * @return array<int, CareerFact>
     */
    private function merge(array $a, array $b): array
    {
        $byKey = [];

        foreach ([...$a, ...$b] as $fact) {
            $byKey[$fact->key] = $fact;
        }

        return array_values($byKey);
    }
}
