<?php

namespace App\Http\Controllers;

use App\Enums\ClarificationSessionStatus;
use App\Models\CareerFact;
use App\Models\CareerLimitation;
use App\Models\ClarificationQuestion;
use App\Models\EvidenceProposal;
use App\Models\JobPosting;
use App\Support\Clarification\ClarificationGate;
use App\Support\Clarification\ReviewEvidenceProposal;
use App\Support\Clarification\ReviseApprovedEvidence;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\CurrentCareerProfile;
use App\Support\TailoredResume\TailoredResumePipeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The optional clarification stage of the one-click pipeline: answer a
 * ranked question, review the evidence it produced, then continue. Every
 * action works on persisted state and redirects back to the job page, so
 * a reload or navigation shows the same thing. Nothing here generates
 * anything except continue(), which resumes the held pipeline run.
 */
class ClarificationController extends Controller
{
    public function answer(Request $request, JobPosting $jobPosting, ClarificationQuestion $question, SubmitClarificationAnswer $submit): RedirectResponse
    {
        $this->ownQuestion($jobPosting, $question);

        $data = $request->validate([
            'mode' => ['required', Rule::in(SubmitClarificationAnswer::MODES)],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string'],
            'answer_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $submit->submit($question, $data['mode'], $data['scopes'] ?? [], $data['answer_text'] ?? '');

        return back();
    }

    /**
     * Explicitly ask for questions, typically on a job that already has a
     * resume. Ranks against the latest analysis and match, current or not,
     * so stale-but-useful results are not thrown away just to ask.
     */
    public function start(Request $request, JobPosting $jobPosting, ClarificationGate $gate, TailoredResumePipeline $pipeline): RedirectResponse
    {
        $data = $request->validate(['mode' => ['sometimes', Rule::in(['standard', 'deep'])]]);

        $analysis = $pipeline->inspect($jobPosting)['analysis'];
        $match = $analysis?->jobMatches()->where('career_profile_id', CurrentCareerProfile::resolve()->id)->latest('generated_at')->latest('id')->first();

        if ($analysis === null || $match === null) {
            throw ValidationException::withMessages(['clarification' => 'Generate a tailored resume once first, so there is a match to ask questions about.']);
        }

        if ($gate->open($jobPosting, $analysis, $match, $data['mode'] ?? 'standard') === null) {
            throw ValidationException::withMessages(['clarification' => 'No question would meaningfully improve this resume.']);
        }

        return back();
    }

    public function skip(JobPosting $jobPosting, ClarificationQuestion $question): RedirectResponse
    {
        $this->ownQuestion($jobPosting, $question);
        $question->update(['status' => 'skipped']);

        return back();
    }

    public function deepen(JobPosting $jobPosting, ClarificationGate $gate): RedirectResponse
    {
        $session = $gate->pendingFor($jobPosting);
        abort_if($session === null, 404);

        $gate->deepen($session);

        return back();
    }

    /**
     * Generate Now (nothing answered) or "Continue" after answering. Both
     * resume the same pipeline run.
     */
    public function continue(JobPosting $jobPosting, ClarificationGate $gate, TailoredResumePipeline $pipeline): RedirectResponse
    {
        $session = $gate->pendingFor($jobPosting);
        abort_if($session === null, 404);

        $answered = $session->questions()->where('status', 'answered')->exists();

        $pipeline->continueAfterClarification(
            $jobPosting,
            $session,
            $answered ? ClarificationSessionStatus::Completed : ClarificationSessionStatus::Skipped,
        );

        return redirect()->route('jobs.show', $jobPosting);
    }

    public function updateProposal(Request $request, EvidenceProposal $evidenceProposal, ReviewEvidenceProposal $review): RedirectResponse
    {
        $this->ownProposal($evidenceProposal);

        $review->update($evidenceProposal, $this->proposalFields($request));

        return back();
    }

    public function approveProposal(Request $request, EvidenceProposal $evidenceProposal, ReviewEvidenceProposal $review): RedirectResponse
    {
        $this->ownProposal($evidenceProposal);

        $review->approve($evidenceProposal, $this->proposalFields($request), [
            'supersede' => $request->boolean('supersede'),
            'revoke_limitation' => $request->boolean('revoke_limitation'),
            'acknowledge_metric' => $request->boolean('acknowledge_metric'),
        ]);

        return back();
    }

    public function rejectProposal(EvidenceProposal $evidenceProposal, ReviewEvidenceProposal $review): RedirectResponse
    {
        $this->ownProposal($evidenceProposal);

        $review->reject($evidenceProposal);

        return back();
    }

    /**
     * Revises user-confirmed evidence as a new revision; the earlier one,
     * the original answer and the history stay on record.
     */
    public function reviseFact(Request $request, CareerFact $careerFact, ReviseApprovedEvidence $revise): RedirectResponse
    {
        abort_unless($careerFact->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        $revise->revise($careerFact, $this->proposalFields($request), ['acknowledge_metric' => $request->boolean('acknowledge_metric')]);

        return back();
    }

    /**
     * Limitations are not permanent: revoking one lets the topic be
     * claimed (with evidence) and asked about again.
     */
    public function revokeLimitation(CareerLimitation $careerLimitation): RedirectResponse
    {
        abort_unless($careerLimitation->career_profile_id === CurrentCareerProfile::resolve()->id, 404);

        $careerLimitation->update(['revoked_at' => now()]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalFields(Request $request): array
    {
        $data = $request->validate([
            'statement' => ['sometimes', 'string', 'max:1000'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string'],
            'attributable_type' => ['sometimes', 'nullable', Rule::in(['employer', 'role', 'project'])],
            'attributable_id' => ['sometimes', 'nullable', 'integer'],
            'visibility' => ['sometimes', Rule::in(['public', 'restricted', 'private'])],
            'verification' => ['sometimes', Rule::in(['verified', 'strongly_supported', 'needs_confirmation'])],
        ]);

        return $data;
    }

    private function ownQuestion(JobPosting $jobPosting, ClarificationQuestion $question): void
    {
        abort_unless($question->session->job_posting_id === $jobPosting->id, 404);
    }

    private function ownProposal(EvidenceProposal $proposal): void
    {
        abort_unless($proposal->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
    }
}
