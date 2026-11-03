<?php

namespace App\Support\CareerData;

use App\Enums\CareerFactType;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\PresentationPreference;
use App\Models\Project;
use App\Models\Role;
use App\Support\Clarification\EvidenceRevision;
use App\Support\Clarification\ReviewEvidenceProposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The portable, version-controllable form of APPROVED user evidence.
 *
 * The database is the operational source of truth. This file is an
 * explicit export of it, and an explicit, conflict-detecting way back in
 * (a new machine, a restored database). Only approved material appears:
 * facts created by an approved clarification, plus limitations and
 * presentation preferences. Proposed and rejected proposals never do.
 *
 * Both directions are idempotent. export() is a pure function of the
 * database (same state, same bytes). sync() creates what is missing,
 * leaves identical rows alone, and REPORTS a conflict for anything that
 * differs, never choosing a side, unless the caller explicitly accepts
 * the file's version.
 *
 * The legacy dataset (canonical-career-data.proposed.json) is not part of
 * this: it stays an import source for the original corpus, and
 * ImportCanonicalCareerData refuses to overwrite approved user evidence.
 */
final class ApprovedEvidenceFile
{
    public const FORMAT = 'career-toolkit.approved-evidence';

    public const VERSION = 1;

    public const DEFAULT_PATH = 'data/approved-career-evidence.json';

    /** A fact is "approved user evidence" when a clarification created it. */
    public static function isApprovedUserEvidence(CareerFact $fact): bool
    {
        return $fact->evidence()->where('metadata->origin', ReviewEvidenceProposal::EVIDENCE_ORIGIN)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function export(CareerProfile $profile): array
    {
        $facts = CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->whereHas('evidence', fn ($q) => $q->where('metadata->origin', ReviewEvidenceProposal::EVIDENCE_ORIGIN))
            ->with(['evidence', 'attributable'])
            ->orderBy('key')
            ->get()
            ->map(fn (CareerFact $fact) => $this->exportFact($fact))
            ->values()
            ->all();

        return [
            '_meta' => [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'note' => 'Approved user evidence only, exported from the database by `php artisan career:export-approved`. Do not edit by hand while the database holds different values; run `career:sync-approved` to detect conflicts.',
            ],
            'facts' => $facts,
            'limitations' => CareerLimitation::query()->where('career_profile_id', $profile->id)->orderBy('topic_key')->orderBy('confirmed_at')->get()
                ->map(fn (CareerLimitation $l) => [
                    'topic_key' => $l->topic_key,
                    'topic' => $l->topic,
                    'terms' => $l->terms,
                    'status' => $l->status,
                    'note' => $l->note,
                    'source' => $l->source,
                    'confirmed_at' => $l->confirmed_at->toIso8601String(),
                    'revoked_at' => $l->revoked_at?->toIso8601String(),
                ])->values()->all(),
            'presentation_preferences' => PresentationPreference::query()->where('career_profile_id', $profile->id)->orderBy('topic_key')->orderBy('confirmed_at')->get()
                ->map(fn (PresentationPreference $p) => [
                    'topic_key' => $p->topic_key,
                    'topic' => $p->topic,
                    'guidance' => $p->guidance,
                    'confirmed_at' => $p->confirmed_at->toIso8601String(),
                    'revoked_at' => $p->revoked_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{created: int, unchanged: int, replaced: int, conflicts: array<int, array<string, string>>}
     */
    public function sync(CareerProfile $profile, array $payload, bool $acceptFile = false): array
    {
        if (($payload['_meta']['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Not an approved-evidence file (unexpected _meta.format).');
        }

        return DB::transaction(function () use ($profile, $payload, $acceptFile) {
            $result = ['created' => 0, 'unchanged' => 0, 'replaced' => 0, 'conflicts' => []];
            $corpusChanged = false;

            foreach ($payload['facts'] ?? [] as $data) {
                $existing = CareerFact::query()->where('key', $data['key'])->first();

                if ($existing === null) {
                    $this->createFact($profile, $data);
                    $result['created']++;
                    $corpusChanged = true;

                    continue;
                }

                if ($this->factHash($existing) === ($data['content_hash'] ?? null)) {
                    $result['unchanged']++;

                    continue;
                }

                if (! $acceptFile) {
                    $result['conflicts'][] = [
                        'key' => $data['key'],
                        'database' => $existing->statement,
                        'file' => $data['statement'],
                    ];

                    continue;
                }

                $this->replaceFact($profile, $existing, $data);
                $result['replaced']++;
                $corpusChanged = true;
            }

            $records = [
                ...array_map(fn (array $data) => $this->syncRecord(CareerLimitation::class, $profile, $data, ['topic', 'terms', 'status', 'note']), $payload['limitations'] ?? []),
                ...array_map(fn (array $data) => $this->syncRecord(PresentationPreference::class, $profile, $data, ['topic', 'guidance']), $payload['presentation_preferences'] ?? []),
            ];

            foreach ($records as [$outcome, $conflict]) {
                if ($conflict !== null) {
                    $result['conflicts'][] = $conflict;
                } else {
                    $result[$outcome]++;
                }
            }

            if ($corpusChanged) {
                EvidenceRevision::bump($profile);
            }

            return $result;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function exportFact(CareerFact $fact): array
    {
        return [
            'key' => $fact->key,
            'fact_type' => $fact->fact_type->value,
            'statement' => $fact->statement,
            'verification' => $fact->verification->value,
            'visibility' => $fact->visibility->value,
            'notes' => $fact->notes,
            'sort_order' => $fact->sort_order,
            // The shared claim identity (not part of the content hash: it is identity, not content).
            'claim_group' => $fact->claim_group,
            'attributable' => $this->attributionRef($fact->attributable),
            'evidence' => $fact->evidence->sortBy('id')->map(fn ($e) => [
                'source' => $e->source->value,
                'quoted_text' => $e->quoted_text,
                'confirmed_at' => $e->confirmed_at?->toIso8601String(),
                'note' => $e->note,
                // Database ids are not portable; keep only the stable keys.
                'metadata' => array_intersect_key($e->metadata ?? [], array_flip(['origin', 'topic_key', 'scopes', 'revision', 'claim_group', 'source_quote'])),
            ])->values()->all(),
            'content_hash' => $this->factHash($fact),
        ];
    }

    private function factHash(CareerFact $fact): string
    {
        return hash('sha256', json_encode([
            $fact->fact_type->value,
            $fact->statement,
            $fact->verification->value,
            $fact->visibility->value,
            $fact->notes,
            $this->attributionRef($fact->attributable),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A portable reference to an attribution target, by natural key.
     *
     * @return array<string, mixed>
     */
    private function attributionRef(?Model $target): array
    {
        return match (true) {
            $target instanceof Project => ['type' => 'project', 'slug' => $target->slug],
            $target instanceof Role => [
                'type' => 'role',
                'employer' => $target->employer->name,
                'title' => $target->title,
                'start_year' => $target->start_year,
                'start_month' => $target->start_month,
            ],
            $target instanceof Employer => ['type' => 'employer', 'name' => $target->name],
            default => ['type' => 'unknown'],
        };
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function resolveAttribution(CareerProfile $profile, array $ref): Model
    {
        $target = match ($ref['type'] ?? null) {
            'project' => Project::query()->where('career_profile_id', $profile->id)->where('slug', $ref['slug'])->first(),
            'employer' => Employer::query()->where('career_profile_id', $profile->id)->where('name', $ref['name'])->first(),
            'role' => Role::query()
                ->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profile->id)->where('name', $ref['employer']))
                ->where('title', $ref['title'])
                ->where('start_year', $ref['start_year'])
                ->where('start_month', $ref['start_month'])
                ->first(),
            default => null,
        };

        return $target ?? throw new InvalidArgumentException('Cannot resolve the attribution target '.json_encode($ref).' in this database. Import the base dataset first.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createFact(CareerProfile $profile, array $data): void
    {
        $target = $this->resolveAttribution($profile, $data['attributable']);

        $fact = CareerFact::create([
            'career_profile_id' => $profile->id,
            'key' => $data['key'],
            'fact_type' => CareerFactType::from($data['fact_type']),
            'statement' => $data['statement'],
            'verification' => $data['verification'],
            'visibility' => $data['visibility'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'attributable_type' => $target->getMorphClass(),
            'attributable_id' => $target->getKey(),
            'claim_group' => $data['claim_group'] ?? null,
        ]);

        $this->replaceEvidence($fact, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replaceFact(CareerProfile $profile, CareerFact $fact, array $data): void
    {
        $target = $this->resolveAttribution($profile, $data['attributable']);

        $fact->update([
            'fact_type' => CareerFactType::from($data['fact_type']),
            'statement' => $data['statement'],
            'verification' => $data['verification'],
            'visibility' => $data['visibility'],
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'attributable_type' => $target->getMorphClass(),
            'attributable_id' => $target->getKey(),
            'claim_group' => $data['claim_group'] ?? null,
        ]);

        $this->replaceEvidence($fact, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replaceEvidence(CareerFact $fact, array $data): void
    {
        $fact->evidence()->delete();

        foreach ($data['evidence'] ?? [] as $evidence) {
            $fact->evidence()->create([
                'source' => $evidence['source'],
                'quoted_text' => $evidence['quoted_text'] ?? null,
                'confirmed_at' => $evidence['confirmed_at'] ?? null,
                'note' => $evidence['note'] ?? null,
                'metadata' => $evidence['metadata'] ?? null,
            ]);
        }
    }

    /**
     * Limitations and preferences are keyed by (topic_key, confirmed_at).
     *
     * @param  class-string<CareerLimitation|PresentationPreference>  $model
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $compare
     * @return array{0: 'created'|'unchanged'|'replaced', 1: array<string, string>|null} the outcome, and a conflict description when the record differs
     */
    private function syncRecord(string $model, CareerProfile $profile, array $data, array $compare): array
    {
        $existing = $model::query()
            ->where('career_profile_id', $profile->id)
            ->where('topic_key', $data['topic_key'])
            ->where('confirmed_at', Carbon::parse($data['confirmed_at']))
            ->first();

        if ($existing === null) {
            $model::create([
                'career_profile_id' => $profile->id,
                ...array_intersect_key($data, array_flip(['topic_key', 'topic', 'terms', 'status', 'note', 'source', 'guidance'])),
                'confirmed_at' => $data['confirmed_at'],
                'revoked_at' => $data['revoked_at'] ?? null,
            ]);

            return ['created', null];
        }

        foreach ($compare as $field) {
            if (($existing->{$field} ?? null) != ($data[$field] ?? null)) {
                return ['unchanged', ['key' => $data['topic_key'], 'database' => (string) json_encode($existing->{$field}), 'file' => (string) json_encode($data[$field] ?? null)]];
            }
        }

        // Revocation is the one legitimate state change: it only ever moves forward.
        if ($existing->revoked_at === null && ($data['revoked_at'] ?? null) !== null) {
            $existing->update(['revoked_at' => $data['revoked_at']]);

            return ['replaced', null];
        }

        return ['unchanged', null];
    }
}
