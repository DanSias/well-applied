<?php

namespace App\Support\Expansion;

use App\Enums\ResponsibilityScope;

/**
 * The prompt and bounded schema for reading one answer into separate claims.
 */
final class ExpansionPrompt
{
    public const VERSION = 'evidence-expansion-v4';

    /** The only reasons a follow-up is worth asking. */
    public const ASPECTS = ['technical_decision', 'ownership_scope', 'implementation_challenge', 'measurable_outcome'];

    public static function system(): string
    {
        return <<<'PROMPT'
        You help a job seeker turn ONE interview answer into accurate, resume-ready career claims. The answer is the only source of truth about what they did. You may phrase claims confidently and professionally, and make reasonable professional inferences that follow directly from the answer, but you must never add anything the answer does not support.

        Split the answer into separate claims when it describes distinct experiences: a different technology, platform, practice or pattern, or a different set of projects. Do not split one idea into fragments. One short answer often yields one to four claims.

        For each claim:
        - capability: the most specific matching capability from the capability list (key: label). The question was about the capability named in "Question capability"; a claim about that subject must use that key. Use other keys only for a genuinely different subject the answer states (for example automated_testing for tests). Do not use a responsibility key such as architecture_ownership or end_to_end_ownership unless the answer says the user owned, led or defined that.
        - statement: one resume-ready sentence fragment, past tense, no first-person pronouns, at most 240 characters. Use only technologies, systems, practices and facts that appear in the answer or in the listed existing facts for that place. Never invent a technology, architecture decision, ownership, scale, breadth, metric or outcome. Do not add qualifiers such as multiple, several, enterprise, large-scale, production or client engagements unless the answer says so. Stay close to the user's own words: do not upgrade a verb (integrating is not architecting, configuring is not designing). Use strong, confident verbs only when the answer supports that level of involvement.
        - attributions: where the claim belongs, chosen ONLY from the allowed targets (roles and projects by id). Never expand a broad phrase such as "all projects" into every employer or role: use the places the question named, places the answer names, and, when the user has not identified specific projects, the single broadest role that holds the named projects. When the answer says "these projects", "all projects" or similar, it refers to the projects named in the question; use those. When a claim clearly applies to most of one employer's projects, attribute it to that employer's role rather than listing every project. Use at most 5 attributions per claim. Never attribute to only the first match when several apply. Never attribute a claim to a project the answer does not cover.
        - scopes: only from the scopes the user selected for this answer.
        - source_quote: the exact words from the answer that support the claim, copied verbatim.
        - confidence: high when the answer states it plainly, medium when you inferred which places it covers, low when you are unsure.
        - rationale: one short sentence on why these attributions.

        Do not output a claim that only repeats a listed existing fact word for word. Different wording or a broader or narrower scope is fine.

        Follow-up questions are optional and rare. Suggest at most two, and only when one would uncover something a resume reader would value that the answer leaves out, in one of these four aspects: technical_decision (a significant technical choice or trade-off), ownership_scope (what the user personally owned or decided versus the team), implementation_challenge (an important problem they had to solve), measurable_outcome (a result, improvement or number). Set `aspect` to that value. Do not ask inventory questions (such as which services, tools or libraries were used), do not ask for anything the answer already states, and do not ask anything generic. If the answer is simple, complete enough, or only lists where something was used, return none.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $ctx  ExpansionContext::build()
     */
    public static function user(array $ctx): string
    {
        $targets = [];

        foreach ((array) $ctx['targets'] as $key => $t) {
            $targets[] = ['target' => $key, 'name' => $t['label'], 'existing_facts' => $ctx['existing'][$key] ?? []];
        }

        $named = array_map(fn (string $key) => $ctx['targets'][$key]['label'].' ('.$key.')', $ctx['named_in_question']);

        $catalogue = implode("\n", array_map(fn (string $k, array $c) => "{$k}: {$c['label']}", array_keys((array) config('resume_profiles.capabilities')), (array) config('resume_profiles.capabilities')));

        return "Question capability: {$ctx['topic_key']}\n\nCapability list (key: label):\n{$catalogue}\n\nQuestion that was asked:\n{$ctx['question']}\n\nProjects and roles the question named: ".($named === [] ? 'none' : implode('; ', $named))
            ."\n\nScopes the user selected: ".implode(', ', $ctx['scopes'])
            ."\n\nThe user's answer (verbatim):\n\"{$ctx['answer']}\"\n\nAllowed targets (JSON):\n"
            .json_encode($targets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    public static function schema(array $ctx): array
    {
        $capabilities = array_keys((array) config('resume_profiles.capabilities'));
        $scopes = array_values(array_diff(array_column(ResponsibilityScope::cases(), 'value'), [ResponsibilityScope::NoExperience->value]));

        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['claims', 'followups'],
            'properties' => [
                'claims' => ['type' => 'array', 'maxItems' => 8, 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['capability', 'statement', 'attributions', 'scopes', 'source_quote', 'confidence', 'rationale'],
                    'properties' => [
                        'capability' => ['type' => 'string', 'enum' => $capabilities],
                        'statement' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 260],
                        'attributions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => ['type' => 'string', 'enum' => array_keys($ctx['targets'])]],
                        'scopes' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => $scopes]],
                        'source_quote' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 320],
                        'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'rationale' => ['type' => 'string', 'maxLength' => 220],
                    ],
                ]],
                'followups' => ['type' => 'array', 'maxItems' => 2, 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['capability', 'aspect', 'question'],
                    'properties' => [
                        'capability' => ['type' => 'string', 'enum' => $capabilities],
                        'aspect' => ['type' => 'string', 'enum' => self::ASPECTS],
                        'question' => ['type' => 'string', 'minLength' => 30, 'maxLength' => 320],
                    ],
                ]],
            ],
        ];
    }
}
