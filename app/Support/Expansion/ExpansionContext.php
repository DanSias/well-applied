<?php

namespace App\Support\Expansion;

use App\Models\CareerFact;
use App\Models\ClarificationAnswer;
use App\Models\Project;
use App\Models\Role;
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
     * @return array{answer: string, scopes: array<int, string>, question: string, topic: string, topic_key: string, named_in_question: array<int, string>, targets: array<string, array<string, mixed>>, parents: array<string, string>, names: array<string, array<int, string>>, existing: array<string, array<int, string>>}
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

        // Which role a project sits under, and the words that name each place: the basis for checking that
        // an attribution is grounded in the question or the answer.
        $parents = [];
        $names = [];

        foreach (Project::query()->where('career_profile_id', $profile->id)->get(['id', 'name', 'role_id']) as $project) {
            if (isset($targets["project:{$project->id}"])) {
                $names["project:{$project->id}"] = [$project->name];

                if ($project->role_id !== null) {
                    $parents["project:{$project->id}"] = "role:{$project->role_id}";
                }
            }
        }

        $roles = Role::query()->whereHas('employer', fn ($q) => $q->where('career_profile_id', $profile->id))->with('employer')->get();
        $rolesPerEmployer = $roles->countBy('employer_id');

        foreach ($roles as $role) {
            if (isset($targets["role:{$role->id}"])) {
                // An employer's name singles out a role only when it is the user's one role there; with
                // several, the employer name alone would name them all.
                $names["role:{$role->id}"] = array_values(array_filter([
                    ...($rolesPerEmployer[$role->employer_id] === 1 ? [$role->employer->name, $role->employer->short_name] : []),
                    $role->title,
                ]));
            }
        }

        $named = [];

        foreach ((array) ($question->context['refs'] ?? []) as $ref) {
            if (isset($targets["{$ref['type']}:{$ref['id']}"])) {
                $named[] = "{$ref['type']}:{$ref['id']}";
            }
        }

        // Places the question's own wording names (a discovery question can name real projects in its text).
        $asked = mb_strtolower($question->question_text);

        foreach ($names as $key => $placeNames) {
            foreach ($placeNames as $name) {
                if (! in_array($key, $named, true) && $name !== '' && str_contains($asked, mb_strtolower($name)) && (str_starts_with($key, 'project:') || mb_strlen($name) > 12)) {
                    $named[] = $key;
                }
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
            'parents' => $parents,
            'names' => $names,
            'existing' => $existing,
        ];
    }
}
