<?php

namespace App\Support\ProfileSynthesis;

use App\Models\CareerProfile;
use App\Support\Clarification\ActivePresentationPreferences;

/**
 * The one model call of profile synthesis: positioning language over a fixed, already-ranked evidence
 * inventory. The model chooses words, never evidence: it can only cite the fact keys it is given, and
 * every sentence it writes is checked afterwards (ProfilePresentationValidator).
 */
final class ProfileSynthesisPrompt
{
    public const VERSION = 'profile-synthesis-v1';

    /** The most facts of each kind the model is shown, so the input stays small and the evidence stays the best. */
    private const PROJECTS_SHOWN = 6;

    private const FACTS_PER_PROJECT = 4;

    private const ACCOMPLISHMENTS_SHOWN = 16;

    public function system(): string
    {
        return <<<'PROMPT'
        You write the professional positioning for a Full-Stack Developer's reference resume profile. You are
        given a ranked evidence inventory: capabilities, projects, accomplishments and the facts behind them.
        You choose words only. You never choose, add or change evidence.

        Write in confident, specific, interview-defensible language: plain active sentences about what the
        candidate built, owned and decided, and what it achieved. No buzzwords, no filler, no first person, no
        em dashes.

        Hard rules:
        1. Every statement cites fact_keys taken ONLY from the facts you were given, and says nothing that
           those cited facts do not support. If you cannot support a sentence, leave it out. When a statement
           names a technology or capability (for example TypeScript, React, REST APIs, Docker), the fact that
           states it must be among that statement's fact_keys: check each named technology against the cited
           facts before you finish. The direct_capabilities list shows which facts state each capability.
        2. Never state a number, percentage, amount, year count or "years of experience" unless that exact
           figure is in a cited fact. Never invent a technology, scale, team size, customer or result.
        2b. Keep a fact's own wording. A figure keeps its qualifier exactly ("approximately 20+ hours" stays that,
           never "over 20"). Capability names in direct_capabilities are categories, not wording: if the fact says
           "developing endpoints", do not upgrade it to "API design". Use the fact's own verbs and nouns.
        3. Direct capabilities may be described as experience. Transferable capabilities are NOT experience:
           do not mention them as something the candidate has done. Never mention a listed limitation.
        4. Keep ownership exactly as the facts state it. Do not turn "worked with", "supported" or
           "collaborated" into "led", "owned" or "architected".
        5. Facts marked personal or whole_career are not employment accomplishments. Never attach them to an
           employer. A statement may name an employer only if a cited fact belongs to that employer.
        6. Facts marked restricted describe confidential work: describe the engineering generally. Never name a
           customer, merchant or counterparty, internal host, or any figure that appears only in a restricted fact.
        7. Preserve what is specific to each project. Do not flatten distinct projects into one generic claim: name
           the project (for example Workflow Intelligence, Nexus) where that makes a statement concrete.
        8. Differentiators must not repeat the summary's sentences. Each covers a different project or technical
           approach, and together they show range, not one achievement said four ways.

        Produce:
        - headline: a short professional title line (at most 12 words) such as a Full-Stack Developer identity.
        - summary: three or four sentences positioning the candidate for broad Full-Stack Developer roles. Lead
          with end-to-end engineering ownership and the strongest technical evidence. Include the most
          significant verified measurable outcome if the facts have one.
        - summary_fact_keys: every fact the summary draws on (up to 12), including the fact for each technology it names.
        - differentiators: three to five distinct technical differentiators, each one sentence, each with fact_keys.
        - project_notes: for each project you are given, one sentence on what makes that project notable, with fact_keys.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $inventory
     */
    public function user(array $inventory, CareerProfile $career, ?string $feedback = null): string
    {
        $prompt = "--- Evidence inventory ---\n".json_encode($this->input($inventory, $career), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n--- End of inventory ---";

        return $feedback === null ? $prompt : $prompt."\n\n--- Your previous response was rejected ---\n{$feedback}\nWrite the response again, fixing exactly the problems listed and changing nothing else.\n--- End of rejection notice ---";
    }

    /**
     * The model-facing view of the inventory: ranked, small, and containing only eligible facts.
     *
     * @param  array<string, mixed>  $inventory
     * @return array<string, mixed>
     */
    public function input(array $inventory, CareerProfile $career): array
    {
        $professional = array_values(array_filter($inventory['projects'], fn (array $p) => ! $p['personal']));
        $projects = array_slice($professional, 0, self::PROJECTS_SHOWN);
        $keys = [];

        foreach ($projects as $p) {
            $keys = [...$keys, ...array_slice($p['fact_keys'], 0, self::FACTS_PER_PROJECT)];
        }

        foreach (array_slice($inventory['accomplishments'], 0, self::ACCOMPLISHMENTS_SHOWN) as $a) {
            $keys[] = $a['fact_key'];
        }

        $direct = array_slice($inventory['capabilities']['direct'], 0, 16);

        foreach ($direct as $c) {
            $keys = [...$keys, ...array_slice($c['fact_keys'], 0, 2)];
        }

        // Whole-career and personal facts the profile may cite, clearly marked as such.
        foreach ($inventory['facts'] as $key => $fact) {
            if ($fact['personal'] && ! ($fact['support_only'] ?? false)) {
                $keys[] = $key;
            }
        }

        $facts = [];

        foreach (array_unique($keys) as $key) {
            $f = $inventory['facts'][$key] ?? null;

            if ($f === null) {
                continue;
            }

            // A restricted fact's figures are withheld from the model: confidential figures must not reach the profile.
            $restricted = $f['visibility'] === 'restricted';
            $facts[$key] = array_filter([
                'statement' => $restricted ? preg_replace('/\$?\d[\d,]*(?:\.\d+)?\+?%?/', '[figure withheld]', $f['statement']) : $f['statement'], 'visibility' => $f['visibility'], 'employer' => $f['employer'], 'role' => $f['role'], 'project' => $f['project'],
                'personal' => $f['personal'] ? true : null, 'whole_career' => $f['whole_career'] ? true : null,
                'metric' => $restricted ? null : $f['metric'], 'also_attached_to' => $f['shared_with'] === [] ? null : $f['shared_with'],
            ], fn ($v) => $v !== null);
        }

        return [
            'target' => $inventory['target']['name'],
            'market' => [
                'pilot_sample' => (bool) ($inventory['provenance']['sample']['pilot'] ?? false),
                'postings' => $inventory['provenance']['sample']['postings'] ?? 0,
                'priorities' => array_map(fn (array $m) => ['capability' => $m['label'], 'share_of_postings' => $m['share'], 'required_in' => $m['required'], 'candidate_status' => $m['status']], array_slice($inventory['market_priorities'], 0, 12)),
            ],
            'direct_capabilities' => array_map(fn (array $c) => ['capability' => $c['label'], 'depth' => $c['depth'], 'fact_keys' => array_slice($c['fact_keys'], 0, 3)], $direct),
            'transferable_capabilities_do_not_claim' => array_column($inventory['capabilities']['transferable'], 'label'),
            'limitations_never_mention' => $inventory['limitations'],
            'projects' => array_map(fn (array $p) => ['project_id' => $p['project_id'], 'name' => $p['name'], 'employer' => $p['employer'], 'why_ranked' => $p['reason'], 'fact_keys' => array_slice($p['fact_keys'], 0, self::FACTS_PER_PROJECT)], $projects),
            'accomplishments' => array_map(fn (array $a) => ['fact_key' => $a['fact_key'], 'kind' => $a['kind']], array_slice($inventory['accomplishments'], 0, self::ACCOMPLISHMENTS_SHOWN)),
            'facts' => $facts,
            'presentation_preferences' => ActivePresentationPreferences::forProfile($career),
        ];
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return array<string, mixed>
     */
    public function schema(array $projectIds): array
    {
        $keys = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'summary_fact_keys' => [...$keys, 'minItems' => 2, 'maxItems' => 12],
                'differentiators' => ['type' => 'array', 'minItems' => 3, 'maxItems' => 5, 'items' => [
                    'type' => 'object',
                    'properties' => ['statement' => ['type' => 'string'], 'fact_keys' => [...$keys, 'minItems' => 1, 'maxItems' => 4]],
                    'required' => ['statement', 'fact_keys'], 'additionalProperties' => false,
                ]],
                'project_notes' => ['type' => 'array', 'maxItems' => 6, 'items' => [
                    'type' => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'enum' => $projectIds === [] ? [-1] : $projectIds], 'note' => ['type' => 'string'], 'fact_keys' => [...$keys, 'minItems' => 1, 'maxItems' => 4]],
                    'required' => ['project_id', 'note', 'fact_keys'], 'additionalProperties' => false,
                ]],
            ],
            'required' => ['headline', 'summary', 'summary_fact_keys', 'differentiators', 'project_notes'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array<int, int>
     */
    public function shownProjectIds(array $inventory): array
    {
        return array_map(fn (array $p) => $p['project_id'], array_slice(array_values(array_filter($inventory['projects'], fn (array $p) => ! $p['personal'])), 0, self::PROJECTS_SHOWN));
    }
}
