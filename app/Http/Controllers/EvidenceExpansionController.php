<?php

namespace App\Http\Controllers;

use App\Jobs\ExpandAnswerClaimsJob;
use App\Models\ClarificationAnswer;
use App\Models\EvidenceExpansion;
use App\Models\EvidenceProposal;
use App\Support\CurrentCareerProfile;
use App\Support\Expansion\ExpandedClaims;
use App\Support\Expansion\ExpansionFollowups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Optional, user-triggered expansion of one answer into separate claims, and review of those claims
 * (edit, approve, reject, merge). Every approval still runs through the normal proposal rules.
 */
class EvidenceExpansionController extends Controller
{
    public function expand(Request $request, ClarificationAnswer $answer): RedirectResponse
    {
        $this->ownAnswer($answer);
        $data = $request->validate(['force' => ['sometimes', 'boolean']]);

        if (Cache::add(ExpandAnswerClaimsJob::lockKey($answer->id), true, now()->addMinutes(12))) {
            ExpandAnswerClaimsJob::dispatch($answer->id, (bool) ($data['force'] ?? false));
        }

        return back();
    }

    public function update(Request $request, string $group, ExpandedClaims $claims): RedirectResponse
    {
        $this->ownGroup($group);
        $data = $request->validate([
            'statement' => ['sometimes', 'string', 'max:1000'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string'],
            'verification' => ['sometimes', Rule::in(['verified', 'strongly_supported', 'needs_confirmation'])],
            'visibility' => ['sometimes', Rule::in(['public', 'restricted', 'private'])],
            'attributions' => ['sometimes', 'array', 'min:1', 'max:8'],
            'attributions.*' => ['string'],
        ]);

        $claims->update($group, $data);

        return back();
    }

    public function approve(Request $request, string $group, ExpandedClaims $claims): RedirectResponse
    {
        $this->ownGroup($group);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(['public', 'restricted', 'private'])],
            'supersede' => ['sometimes', 'array'],
            'supersede.*' => ['integer'],
            'visibilities' => ['sometimes', 'array'],
            'visibilities.*' => [Rule::in(['public', 'restricted', 'private'])],
            'acknowledge_metric' => ['sometimes', 'boolean'],
        ]);

        $claims->approve($group, $data['visibility'], array_map('intval', $data['supersede'] ?? []), $data['visibilities'] ?? [], (bool) ($data['acknowledge_metric'] ?? false));

        return back();
    }

    public function reject(string $group, ExpandedClaims $claims): RedirectResponse
    {
        $this->ownGroup($group);
        $claims->reject($group);

        return back();
    }

    public function merge(Request $request, string $group, ExpandedClaims $claims): RedirectResponse
    {
        $this->ownGroup($group);
        $data = $request->validate(['into' => ['required', 'string']]);
        $this->ownGroup($data['into']);
        $claims->merge($group, $data['into']);

        return back();
    }

    public function askFollowup(EvidenceExpansion $expansion, int $index, ExpansionFollowups $followups): RedirectResponse
    {
        abort_unless($expansion->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
        $followups->ask($expansion, $index);

        return back();
    }

    private function ownAnswer(ClarificationAnswer $answer): void
    {
        abort_unless($answer->question->session->ownerCareerProfile()->id === CurrentCareerProfile::resolve()->id, 404);
    }

    private function ownGroup(string $group): void
    {
        abort_unless(EvidenceProposal::query()->where('claim_group', $group)->where('career_profile_id', CurrentCareerProfile::resolve()->id)->exists(), 404);
    }
}
