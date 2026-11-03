<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReferenceResumeJob;
use App\Jobs\SynthesizeProfileJob;
use App\Models\ResumeProfile;
use App\Models\ResumeProfileSynthesis;
use App\Support\CurrentCareerProfile;
use App\Support\ProfileSynthesis\ReviewProfileSynthesis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The profile synthesis lifecycle on the Resume Profile page: build (deliberate, queued), review and edit,
 * approve, then generate the reference resume from the approved version. Nothing here touches career facts.
 */
class ProfileSynthesisController extends Controller
{
    public function store(Request $request, ResumeProfile $resumeProfile): RedirectResponse
    {
        abort_unless($resumeProfile->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
        $data = $request->validate(['force' => ['sometimes', 'boolean']]);

        if (Cache::add(SynthesizeProfileJob::lockKey($resumeProfile->id), true, now()->addMinutes(25))) {
            SynthesizeProfileJob::dispatch($resumeProfile->id, (bool) ($data['force'] ?? false));
        }

        return back();
    }

    public function update(Request $request, ResumeProfileSynthesis $synthesis, ReviewProfileSynthesis $review): RedirectResponse
    {
        $this->own($synthesis);
        $data = $request->validate([
            'id' => ['required_without:reorder', 'string', 'max:120'],
            'include' => ['sometimes', 'boolean'], 'pinned' => ['sometimes', 'boolean'], 'text' => ['sometimes', 'string', 'max:1200'],
            'reorder' => ['sometimes', 'array', 'max:80'], 'reorder.*' => ['string', 'max:120'],
        ]);

        isset($data['reorder']) ? $review->reorder($synthesis, $data['reorder']) : $review->edit($synthesis, $data['id'], array_intersect_key($data, array_flip(['include', 'pinned', 'text'])));

        return back();
    }

    public function approve(ResumeProfileSynthesis $synthesis, ReviewProfileSynthesis $review): RedirectResponse
    {
        $this->own($synthesis);
        $review->approve($synthesis);

        return back();
    }

    public function newVersion(ResumeProfileSynthesis $synthesis, ReviewProfileSynthesis $review): RedirectResponse
    {
        $this->own($synthesis);
        $review->newVersion($synthesis);

        return back();
    }

    public function generate(ResumeProfileSynthesis $synthesis): RedirectResponse
    {
        $this->own($synthesis);

        if ($synthesis->status !== 'approved' || $synthesis->isStale()) {
            throw ValidationException::withMessages(['profile' => 'Generate the reference resume from a current, approved profile.']);
        }

        if (Cache::add(GenerateReferenceResumeJob::lockKey($synthesis->id), true, now()->addMinutes(55))) {
            GenerateReferenceResumeJob::dispatch($synthesis->id);
        }

        return back();
    }

    private function own(ResumeProfileSynthesis $synthesis): void
    {
        abort_unless($synthesis->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
    }
}
