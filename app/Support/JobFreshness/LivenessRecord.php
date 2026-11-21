<?php

namespace App\Support\JobFreshness;

/**
 * `discovery_metadata.liveness`: the stored observations and the last decision. A sibling of `discovery` and `canonical`, so a rediscovery (which replaces `discovery`
 * wholesale) can never erase or rewrite it. PURE: it builds the array a later write gate would store; B3 stores nothing.
 */
final class LivenessRecord
{
    public const SCHEMA = 1;

    /**
     * @param  array<string, mixed>|null  $metadata  the job's current discovery_metadata
     * @return list<Observation> chronological, validated; malformed entries dropped
     */
    public static function observations(?array $metadata): array
    {
        $raw = $metadata['liveness']['observations'] ?? [];
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            $o = Observation::fromArray($entry);
            if ($o !== null) {
                $out[] = $o;
            }
        }
        usort($out, fn (Observation $a, Observation $b) => $a->observedAt <=> $b->observedAt);

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  list<Observation>  $new
     * @return array<string, mixed> the whole discovery_metadata, with only `liveness` changed
     */
    public static function with(?array $metadata, array $new, LivenessDecision $decision, int $cap = 10): array
    {
        $merged = [];
        foreach ([...self::observations($metadata), ...$new] as $o) {
            // The same observation recorded twice is one observation.
            $merged[implode('|', [$o->provider, $o->source, $o->result, $o->basis, $o->observedAt->toIso8601String()])] = $o;
        }
        $all = array_values($merged);
        usort($all, fn (Observation $a, Observation $b) => $a->observedAt <=> $b->observedAt);
        $all = array_slice($all, -max(1, $cap));

        $metadata = $metadata ?? [];
        $metadata['liveness'] = [
            'schema' => self::SCHEMA,
            'observations' => array_map(fn (Observation $o) => $o->toArray(), $all),
            'decision' => ['state' => $decision->state->value, 'action' => $decision->action, 'reason' => $decision->reason],
        ];

        return $metadata;
    }
}
