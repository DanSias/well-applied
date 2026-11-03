<?php

namespace App\Support\Expansion;

use App\Models\ClarificationQuestion;
use App\Models\EvidenceExpansion;
use Illuminate\Validation\ValidationException;

/**
 * Turns a suggested follow-up into a real interview question, only when the user asks for it.
 */
final class ExpansionFollowups
{
    public function ask(EvidenceExpansion $expansion, int $index): ClarificationQuestion
    {
        $followup = ($expansion->result['followups'] ?? [])[$index] ?? null;
        $answer = $expansion->answer;
        $session = $answer->question->session;

        if ($followup === null || $session->resume_profile_id === null) {
            throw ValidationException::withMessages(['followup' => 'That follow-up is not available.']);
        }

        $existing = $session->questions()->where('context->followup_of_answer', $answer->id)->where('question_text', $followup['question'])->first();

        if ($existing !== null) {
            return $existing;
        }

        $label = (string) config("resume_profiles.capabilities.{$followup['capability']}.label", $followup['capability']);

        return $session->questions()->create([
            'topic_key' => $followup['capability'], 'topic_label' => $label, 'related_finding_ids' => [], 'question_text' => $followup['question'],
            'score' => 0.5, 'score_breakdown' => ['source' => 'followup'],
            'context' => ['source' => 'followup', 'kind' => 'deepening', 'followup_of_answer' => $answer->id, 'refs' => $answer->question->context['refs'] ?? [], 'related_evidence' => []],
            'suggested_attributable_type' => $answer->question->suggested_attributable_type, 'suggested_attributable_id' => $answer->question->suggested_attributable_id,
            'suggested_visibility' => 'restricted', 'status' => 'open', 'sort_order' => (int) $session->questions()->max('sort_order') + 1,
        ]);
    }
}
