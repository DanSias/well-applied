<?php

namespace App\Http\Controllers;

use App\Models\EvidenceConsolidation;
use App\Support\Consolidation\ApplyConsolidation;
use App\Support\CurrentCareerProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Review of the evidence-consolidation queue. Every item is decided on its own; approval is the only
 * thing that changes a career fact (see ApplyConsolidation).
 */
class EvidenceConsolidationController extends Controller
{
    public function update(Request $request, EvidenceConsolidation $consolidation, ApplyConsolidation $apply): RedirectResponse
    {
        $this->own($consolidation);
        $apply->update($consolidation, $this->fields($request));

        return back();
    }

    public function approve(Request $request, EvidenceConsolidation $consolidation, ApplyConsolidation $apply): RedirectResponse
    {
        $this->own($consolidation);
        $data = $request->validate(['acknowledge_metric' => ['sometimes', 'boolean']]);
        $apply->approve($consolidation, $this->fields($request), ['acknowledge_metric' => (bool) ($data['acknowledge_metric'] ?? false)]);

        return back();
    }

    public function reject(EvidenceConsolidation $consolidation, ApplyConsolidation $apply): RedirectResponse
    {
        $this->own($consolidation);
        $apply->reject($consolidation);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(Request $request): array
    {
        return $request->validate([
            'statement' => ['sometimes', 'string', 'max:1200'],
            'attributable_type' => ['sometimes', Rule::in(['career_profile', 'role', 'project'])],
            'attributable_id' => ['required_with:attributable_type', 'integer'],
            'visibility' => ['sometimes', Rule::in(['public', 'restricted', 'private'])],
        ]);
    }

    private function own(EvidenceConsolidation $consolidation): void
    {
        abort_unless($consolidation->career_profile_id === CurrentCareerProfile::resolve()->id, 404);
    }
}
