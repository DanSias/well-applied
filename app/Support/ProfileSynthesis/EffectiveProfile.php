<?php

namespace App\Support\ProfileSynthesis;

use App\Models\ResumeProfileSynthesis;

/**
 * The profile as the user has shaped it: the evidence inventory and the written positioning, with every
 * include, exclude, pin, reorder and wording decision applied. The stored `content` is never changed, so
 * the original synthesis stays inspectable beside the user's decisions. Everything downstream (the review
 * page, the reference resume's selection) reads this, never the raw content.
 */
final class EffectiveProfile
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(ResumeProfileSynthesis $synthesis): array
    {
        $inventory = $synthesis->content['inventory'];
        $presentation = $synthesis->content['presentation'];
        $edits = $synthesis->edits ?? [];
        $items = (array) ($edits['items'] ?? []);
        $text = (array) ($edits['text'] ?? []);
        // The facts the user chose to cite for a piece of language, in place of the ones the model cited.
        $keys = (array) ($edits['keys'] ?? []);

        $decorate = function (array $rows) use ($items): array {
            $rows = array_map(fn (array $row) => [
                ...$row,
                'included' => (bool) ($items[$row['id']]['include'] ?? true),
                'pinned' => (bool) ($items[$row['id']]['pinned'] ?? false),
                'order' => $items[$row['id']]['order'] ?? null,
                'edited' => isset($items[$row['id']]),
            ], $rows);

            // Pinned first, then the user's own order, then the synthesized rank.
            usort($rows, fn (array $a, array $b) => [$a['pinned'] ? 0 : 1, $a['order'] ?? PHP_INT_MAX, $a['rank'] ?? PHP_INT_MAX] <=> [$b['pinned'] ? 0 : 1, $b['order'] ?? PHP_INT_MAX, $b['rank'] ?? PHP_INT_MAX]);

            return $rows;
        };

        $differentiators = [];

        foreach ((array) $presentation['differentiators'] as $i => $d) {
            $id = "differentiator:{$i}";
            $differentiators[] = ['id' => $id, 'rank' => $i + 1, 'statement' => $text[$id] ?? $d['statement'], 'original' => $d['statement'], 'fact_keys' => $keys[$id] ?? $d['fact_keys'], 'original_fact_keys' => $d['fact_keys']];
        }

        $notes = [];

        foreach ((array) $presentation['project_notes'] as $i => $n) {
            $id = "note:{$n['project_id']}";
            $notes[] = ['id' => $id, 'rank' => $i + 1, 'project_id' => $n['project_id'], 'note' => $text[$id] ?? $n['note'], 'original' => $n['note'], 'fact_keys' => $keys[$id] ?? $n['fact_keys'], 'original_fact_keys' => $n['fact_keys']];
        }

        return [
            'provenance' => $inventory['provenance'],
            'market_priorities' => $inventory['market_priorities'],
            'limitations' => $inventory['limitations'],
            'excluded' => $inventory['excluded'],
            'private_omitted' => $inventory['private_omitted'],
            'roles' => $inventory['roles'],
            'facts' => $inventory['facts'],
            'capabilities' => [
                'direct' => $decorate($inventory['capabilities']['direct']),
                'transferable' => $inventory['capabilities']['transferable'],
                'underdocumented' => $inventory['capabilities']['underdocumented'],
            ],
            'projects' => $decorate($inventory['projects']),
            'accomplishments' => $decorate($inventory['accomplishments']),
            'presentation' => [
                'headline' => $text['headline'] ?? $presentation['headline'], 'headline_original' => $presentation['headline'],
                'summary' => $text['summary'] ?? $presentation['summary'], 'summary_original' => $presentation['summary'], 'summary_fact_keys' => $keys['summary'] ?? $presentation['summary_fact_keys'], 'summary_fact_keys_original' => $presentation['summary_fact_keys'],
                'differentiators' => $decorate($differentiators),
                'project_notes' => $notes,
            ],
        ];
    }

    /**
     * The presentation in the validator's shape, with excluded differentiators removed.
     *
     * @param  array<string, mixed>  $effective
     * @return array<string, mixed>
     */
    public static function presentationForValidation(array $effective): array
    {
        $p = $effective['presentation'];
        $includedProjects = array_column(array_filter($effective['projects'], fn (array $row) => $row['included']), 'project_id');

        return [
            'headline' => $p['headline'], 'summary' => $p['summary'], 'summary_fact_keys' => $p['summary_fact_keys'],
            'differentiators' => array_values(array_map(fn (array $d) => ['statement' => $d['statement'], 'fact_keys' => $d['fact_keys']], array_filter($p['differentiators'], fn (array $d) => $d['included']))),
            'project_notes' => array_values(array_map(fn (array $n) => ['project_id' => $n['project_id'], 'note' => $n['note'], 'fact_keys' => $n['fact_keys']], array_filter($p['project_notes'], fn (array $n) => in_array($n['project_id'], $includedProjects, true)))),
        ];
    }
}
