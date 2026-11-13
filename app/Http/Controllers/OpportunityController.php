<?php

namespace App\Http\Controllers;

use App\Support\Opportunities\PresentOpportunities;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Opportunities": jobs the user has explicitly decided to pursue (disposition = pursued). There is no opportunity
 * table; this is a view over JobPosting and the work that already hangs off it. Preparing a resume, inspecting the
 * application form and the rest happen in the existing job and resume workspaces this page links into.
 */
class OpportunityController extends Controller
{
    public function index(Request $request, PresentOpportunities $opportunities): Response
    {
        $resume = in_array($request->query('resume'), ['none', 'in-progress', 'final'], true) ? (string) $request->query('resume') : 'all';

        return Inertia::render('opportunities/index', $opportunities->present($resume) + ['filters' => ['resume' => $resume]]);
    }
}
