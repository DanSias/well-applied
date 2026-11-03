<?php

namespace App\Support\Expansion;

use App\Models\CareerFact;
use App\Models\ClarificationAnswer;
use App\Models\Project;
use App\Support\Clarification\PresentClarification;
use App\Support\ResumeVariant\ResumeEligibility;

/**
 * What the model may know when it reads one answer: the user's real roles and projects (the only
 * places a claim can be attributed), what the question named, and the short statements of facts
 * already recorded on those places, so it does not restate them. Only resume-eligible facts are
 * included: Private evidence never reaches the model.
 */
final class ExpansionContext
{
    public function __construct(private readonly PresentClarification $options) {}

    /**
     * @return array{answer: string, scopes: array<int, string>, question: string, topic: string, topic_key: string, named_in_question: array<int, string>, targets: array<string, array<string, mixed>>, existing: array<string, array<int, string>>}
     */
    public function build(ClarificationAnswer $answer): array
    {
        $question = $answer->question;
        $profile = $question->session->ownerCareerProfile();
        $private = Project::query()->where('career_profile_id', $profile->id)->where('default_visibility', 'private')->pluck('id')->all();

        $targets = [];

        foreach ($this->options->attributionOptions($profile->id) as $o) {
            if (! in_array($o['type'], ['role', 'project'], true) || ($o['type'] === 'project' && in_array($o['id'], $private, true))) {
                continue;
            }

            $targets["{$o['type']}:{$o['id']}"] = ['type' => $o['type'], 'id' => $o['id'], 'label' => $o['label']];
        }

        $named = [];

        foreach ((array) ($question->context['refs'] ?? []) as $ref) {
            if (isset($targets["{$ref['type']}:{$ref['id']}"])) {
                $named[] = "{$ref['type']}:{$ref['id']}";
            }
        }

        $existing = [];

        foreach (ResumeEligibility::eligibleCareerFactsQuery($profile)->whereIn('attributable_type', ['role', 'project'])->orderBy('id')->get() as $fact) {
            /** @var CareerFact $fact */
            $key = "{$fact->attributable_type}:{$fact->attributable_id}";

            if (isset($targets[$key]) && count($existing[$key] ?? []) < 2) {
                $existing[$key][] = mb_strimwidth($fact->statement, 0, 150, '...');
            }
        }

        return [
            'answer' => $answer->answer_text,
            'scopes' => array_values((array) $answer->scopes),
            'question' => $question->question_text,
            'topic' => $question->topic_label,
            'topic_key' => $question->topic_key,
            'named_in_question' => $named,
            'targets' => $targets,
            'existing' => $existing,
        ];
    }
}
