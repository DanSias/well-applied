<?php

namespace App\Support\ResumeVariant;

/**
 * The evidence the wording model is shown, without what it cannot use. A candidate fact carries ids, role dates, skill
 * categories, empty annotations and an empty metric for the validators and the database; sent as prompt text they were
 * about a third of the tokens of every fact. What the model needs is kept exactly: the key it cites, the statement, the
 * visibility (restricted evidence is worded more carefully), the project and the technologies the fact authorizes (by
 * name), a metric's scope note or guardrail when there is one, whether the claim is shared with other projects, and
 * whether a first-person fact is for support only. Nothing is summarized or reworded.
 *
 * A fact the summary may use that a bullet group already carries is not sent a second time: the summary lists its key.
 */
final class SlimWordingFacts
{
    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    public static function fact(array $fact, bool $withRole = false): array
    {
        $out = ['key' => $fact['key'], 'statement' => $fact['statement'], 'visibility' => $fact['visibility']];

        if (($fact['fact_type'] ?? 'bullet') !== 'bullet') {
            $out['fact_type'] = $fact['fact_type'];
        }

        $attribution = (array) ($fact['attribution'] ?? []);

        if (! empty($attribution['project'])) {
            $out['project'] = $attribution['project'];
        }

        if ($withRole && ! empty($attribution['role'])) {
            $out['role'] = $attribution['role'];
        }

        if (! empty($fact['skills'])) {
            $out['skills'] = array_values(array_map(fn ($s) => is_array($s) ? $s['name'] : $s, (array) $fact['skills']));
        }

        foreach (['metric', 'job_match_annotations'] as $key) {
            if (! empty($fact[$key])) {
                $out[$key] = $fact[$key];
            }
        }

        if (! empty($fact['shared_claim']['also_attached_to'])) {
            $out['also_attached_to'] = $fact['shared_claim']['also_attached_to'];
        }

        if (! empty($fact['support_only'])) {
            $out['support_only'] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input  buildWordingInput() (plus the editorial plan)
     * @return array<string, mixed>
     */
    public static function input(array $input): array
    {
        $inBullets = [];

        foreach ($input['experience'] as $r => $role) {
            foreach ($role['bullet_groups'] as $g => $group) {
                $input['experience'][$r]['bullet_groups'][$g]['career_facts'] = array_map(fn (array $f) => self::fact($f), $group['career_facts']);

                foreach ($group['career_facts'] as $f) {
                    $inBullets[$f['key']] = true;
                }
            }
        }

        $summary = array_values(array_filter((array) $input['summary_evidence']));
        $input['summary_evidence_keys'] = array_column($summary, 'key');
        // Only the facts the bullets do not already show are sent in full.
        $input['summary_evidence'] = array_values(array_map(fn (array $f) => self::fact($f, withRole: true), array_filter($summary, fn (array $f) => ! isset($inBullets[$f['key']]))));

        return $input;
    }
}
