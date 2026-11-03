<?php

namespace App\Http\Controllers;

use App\Jobs\BuildResumeProfileJob;
use App\Models\ClarificationQuestion;
use App\Models\MarketSamplePosting;
use App\Models\ResumeProfile;
use App\Support\Clarification\SubmitClarificationAnswer;
use App\Support\CurrentCareerProfile;
use App\Support\ResumeProfile\PresentResumeProfile;
use App\Support\ResumeProfile\ProfileDefinitions;
use App\Support\ResumeProfile\ProfileInterview;
use App\Support\ResumeProfile\SampleReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resume Profiles: study the market, then interview. Every page is derived
 * from persisted state, so progress survives leaving and returning. Answers
 * go through the same clarification endpoints and evidence proposals as a
 * job-specific question.
 */
class ResumeProfileController extends Controller
{
    public function index(): Response
    {
        $career = CurrentCareerProfile::resolve();

        return Inertia::render('resume-profiles/index', [
            'profiles' => collect(ProfileDefinitions::slugs())->map(function (string $slug) use ($career) {
                $profile = ProfileDefinitions::ensure($career, $slug);

                return ['slug' => $slug, 'name' => $profile->name, 'purpose' => $profile->purpose, 'status_label' => $profile->status->label()];
            })->values()->all(),
        ]);
    }

    public function show(ResumeProfile $resumeProfile, PresentResumeProfile $present, ProfileInterview $interview): Response
    {
        $this->own($resumeProfile);

        // Returning after evidence changed: cover what is now documented, add what the ranking now favours. Deterministic and idempotent.
        if ($resumeProfile->market_sample_id !== null && $interview->progress($resumeProfile)['evidence_changed']) {
            $interview->sync($resumeProfile);
        }

        return Inertia::render('resume-profiles/show', ['resume_profile' => $present->present($resumeProfile)]);
    }

    /**
     * "Build My Full-Stack Profile": queue the market study and interview setup.
     */
    public function build(Request $request, ResumeProfile $resumeProfile): RedirectResponse
    {
        $this->own($resumeProfile);
        $data = $request->validate(['pilot' => ['sometimes', 'boolean']]);

        $building = $resumeProfile->samples()->where('status', 'collecting')->where('created_at', '>=', now()->subMinutes(15))->exists();

        if (! $building) {
            BuildResumeProfileJob::dispatch($resumeProfile->id, (bool) ($data['pilot'] ?? false));
        }

        return back();
    }

    /**
     * Re-ranks the interview against the current evidence without refetching the market.
     */
    public function refresh(ResumeProfile $resumeProfile, ProfileInterview $interview): RedirectResponse
    {
        $this->own($resumeProfile);
        $interview->sync($resumeProfile);

        return back();
    }

    public function answer(Request $request, ResumeProfile $resumeProfile, ClarificationQuestion $question, SubmitClarificationAnswer $submit): RedirectResponse
    {
        $this->ownQuestion($resumeProfile, $question);

        $data = $request->validate([
            'mode' => ['required', Rule::in(SubmitClarificationAnswer::MODES)],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string'],
            'answer_text' => ['nullable', 'string', 'max:4000'],
        ]);

        $submit->submit($question, $data['mode'], $data['scopes'] ?? [], (string) ($data['answer_text'] ?? ''));

        return back();
    }

    public function skip(ResumeProfile $resumeProfile, ClarificationQuestion $question, ProfileInterview $interview): RedirectResponse
    {
        $this->ownQuestion($resumeProfile, $question);
        $interview->skip($question);

        return back();
    }

    public function reopen(ResumeProfile $resumeProfile, ClarificationQuestion $question, ProfileInterview $interview): RedirectResponse
    {
        $this->ownQuestion($resumeProfile, $question);
        $interview->reopen($question);

        return back();
    }

    public function excludePosting(Request $request, ResumeProfile $resumeProfile, MarketSamplePosting $posting, SampleReview $review): RedirectResponse
    {
        $this->ownPosting($resumeProfile, $posting);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $review->exclude($resumeProfile, $posting, $data['reason'] ?? null);

        return back();
    }

    public function restorePosting(ResumeProfile $resumeProfile, MarketSamplePosting $posting, SampleReview $review): RedirectResponse
    {
        $this->ownPosting($resumeProfile, $posting);
        $review->restore($resumeProfile, $posting);

        return back();
    }

    private function ownPosting(ResumeProfile $profile, MarketSamplePosting $posting): void
    {
        $this->own($profile);
        abort_unless($posting->market_sample_id === $profile->market_sample_id, 404);
    }

    private function own(ResumeProfile $profile): void
    {
        abort_unless($profile->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
    }

    private function ownQuestion(ResumeProfile $profile, ClarificationQuestion $question): void
    {
        $this->own($profile);
        abort_unless($question->session->resume_profile_id === $profile->id, 404);
    }
}
