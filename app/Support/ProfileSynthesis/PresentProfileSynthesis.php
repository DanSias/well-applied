<?php

namespace App\Support\ProfileSynthesis;

use App\Jobs\GenerateReferenceResumeJob;
use App\Jobs\SynthesizeProfileJob;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use Illuminate\Support\Facades\Cache;

/**
 * The profile as the Resume Profile page shows it: the version being worked on, the user's edits applied,
 * its provenance and validation notes, and the reference resume benchmark once one exists. Private evidence
 * is never in a synthesis, so none can appear here.
 */
final class PresentProfileSynthesis
{
    /**
     * @return array<string, mixed>
     */
    public function present(ResumeProfile $profile): array
    {
        $versions = ResumeProfileSynthesis::query()->where('resume_profile_id', $profile->id)->orderByDesc('version')->get();
        $shown = $versions->firstWhere('status', 'draft') ?? $versions->firstWhere('status', 'approved');
        $approved = $versions->firstWhere('status', 'approved');

        return [
            'building' => Cache::has(SynthesizeProfileJob::lockKey($profile->id)),
            'error' => Cache::get(SynthesizeProfileJob::errorKey($profile->id)),
            'versions' => $versions->map(fn (ResumeProfileSynthesis $v) => ['id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'stale' => $v->isStale(), 'approved_at' => $v->approved_at?->toIso8601String()])->values()->all(),
            'approved_version' => $approved?->version,
            'synthesis' => $shown === null ? null : $this->synthesis($shown),
            'reference' => $approved === null ? null : $this->reference($approved),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function synthesis(ResumeProfileSynthesis $s): array
    {
        $effective = EffectiveProfile::resolve($s);
        $keys = [];

        foreach ([...$effective['projects'], ...$effective['accomplishments'], ...$effective['capabilities']['direct']] as $row) {
            $keys = [...$keys, ...($row['fact_keys'] ?? []), ...(isset($row['fact_key']) ? [$row['fact_key']] : [])];
        }

        foreach ([...$effective['presentation']['differentiators'], ...$effective['presentation']['project_notes']] as $row) {
            $keys = [...$keys, ...$row['fact_keys']];
        }

        $keys = [...$keys, ...$effective['presentation']['summary_fact_keys']];
        $effective['facts'] = array_intersect_key($effective['facts'], array_flip($keys));
        $effective['accomplishments'] = array_slice($effective['accomplishments'], 0, 40);

        return [
            'id' => $s->id, 'version' => $s->version, 'status' => $s->status, 'stale' => $s->isStale(), 'pilot' => $s->pilot, 'generated_by' => $s->generated_by,
            'prompt_version' => $s->prompt_version, 'created_at' => $s->created_at?->toIso8601String(), 'approved_at' => $s->approved_at?->toIso8601String(),
            'model_calls' => $s->usage['model_calls'] ?? null, 'duration_ms' => $s->usage['duration_ms'] ?? null,
            'notes' => (array) ($s->validation['notes'] ?? []), 'profile' => $effective,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(ResumeProfileSynthesis $s): array
    {
        $r = (array) ($s->usage['reference'] ?? []);
        $variantId = $r['variant_id'] ?? null;

        return [
            'synthesis_id' => $s->id, 'running' => Cache::has(GenerateReferenceResumeJob::lockKey($s->id)), 'state' => $r['state'] ?? null, 'phase' => $r['phase'] ?? null, 'error' => $r['error'] ?? null,
            'variant_id' => $variantId,
            'preview_url' => $variantId === null ? null : route('resume-variants.preview', $variantId),
            'download_url' => $variantId === null ? null : route('resume-variants.pdf', $variantId),
            'duration_ms' => $r['duration_ms'] ?? null, 'wording_ms' => $r['wording_ms'] ?? null, 'model_calls' => $r['model_calls'] ?? null, 'pages' => $r['pages'] ?? null,
            'tokens' => $r['tokens'] ?? null, 'stale' => $s->isStale(),
        ];
    }
}
