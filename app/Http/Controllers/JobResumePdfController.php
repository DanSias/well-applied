<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Support\ResumeRevision\JobResumes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The job's current resume as a PDF: GET /jobs/{jobPosting}/resume/pdf.
 *
 * The job page's Download PDF must give the resume the job is working from now. Linking it to a variant id would freeze
 * the page's idea of "current" at the moment it loaded, so a revision saved afterwards (in another tab, or before a Back
 * navigation restored the old page) would leave the button serving the superseded version. This route resolves the
 * current resume (JobResumes::current, the same rule the workspace and the status payload use) when it is requested, then
 * serves it through the same generate, validate and respond path as the per-variant route, so a PDF is byte-identical
 * either way. Every response names the variant it served in X-Resume-Variant-Id. The per-variant route is unchanged and
 * remains how a specific, including a historical, version is downloaded.
 */
class JobResumePdfController extends Controller
{
    public function show(Request $request, JobPosting $jobPosting, JobResumes $resumes, ResumeVariantPdfController $pdf): Response|JsonResponse
    {
        $variant = $resumes->current($jobPosting);

        abort_if($variant === null, 404, 'This job has no tailored resume yet.');

        /** @var Response|JsonResponse $response */
        $response = app()->call([$pdf, 'show'], ['request' => $request, 'resumeVariant' => $variant]);

        // Which resume this is can change at any time, so no cache may keep an answer for this URL.
        $response->headers->set('X-Resume-Variant-Id', (string) $variant->id);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
