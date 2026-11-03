<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationInspectionStatusController;
use App\Http\Controllers\BrowserWorkerStatusController;
use App\Http\Controllers\CareerDataController;
use App\Http\Controllers\ClarificationController;
use App\Http\Controllers\EvidenceExpansionController;
use App\Http\Controllers\GenerationAttemptController;
use App\Http\Controllers\JobAnalysisController;
use App\Http\Controllers\JobBatchController;
use App\Http\Controllers\JobMatchController;
use App\Http\Controllers\JobPostingController;
use App\Http\Controllers\ResumeProfileController;
use App\Http\Controllers\ResumeVariantController;
use App\Http\Controllers\ResumeVariantPdfController;
use App\Http\Controllers\ResumeVariantPreviewController;
use App\Http\Controllers\TailoredResumeController;
use App\Http\Middleware\AllowLongRunningGeneration;
use Illuminate\Support\Facades\Route;

Route::get('/', [CareerDataController::class, 'index'])->name('career-data.index');
Route::get('/career-data/facts/{careerFact}', [CareerDataController::class, 'showFact'])->name('career-data.facts.show');

Route::get('/jobs', [JobPostingController::class, 'index'])->name('jobs.index');
Route::get('/jobs/create', [JobPostingController::class, 'create'])->name('jobs.create');
Route::post('/jobs', [JobPostingController::class, 'store'])->name('jobs.store');
Route::get('/jobs/{jobPosting}', [JobPostingController::class, 'show'])->name('jobs.show');

// One-click "Generate Tailored Resume": starts (or resumes) the durable
// analysis -> match -> resume -> PDF pipeline, and a read-only unified
// status for polling. Never involves Application inspection. See
// App\Support\TailoredResume\TailoredResumePipeline.
Route::post('/jobs/{jobPosting}/tailored-resume', [TailoredResumeController::class, 'store'])->name('jobs.tailored-resume.store');
Route::get('/jobs/{jobPosting}/tailored-resume/status', [TailoredResumeController::class, 'status'])->name('jobs.tailored-resume.status');

// Optional targeted clarification between matching and Selection:
// answer ranked questions, review the evidence proposals they produce,
// then continue (or Generate Now). See App\Support\Clarification.
Route::post('/jobs/{jobPosting}/clarification/start', [ClarificationController::class, 'start'])->name('jobs.clarification.start');
Route::post('/jobs/{jobPosting}/clarification/questions/{question}/answer', [ClarificationController::class, 'answer'])->name('jobs.clarification.answer');
Route::post('/jobs/{jobPosting}/clarification/questions/{question}/skip', [ClarificationController::class, 'skip'])->name('jobs.clarification.skip');
Route::post('/jobs/{jobPosting}/clarification/deepen', [ClarificationController::class, 'deepen'])->name('jobs.clarification.deepen');
Route::post('/jobs/{jobPosting}/clarification/continue', [ClarificationController::class, 'continue'])->name('jobs.clarification.continue');
Route::patch('/evidence-proposals/{evidenceProposal}', [ClarificationController::class, 'updateProposal'])->name('evidence-proposals.update');
Route::post('/evidence-proposals/{evidenceProposal}/approve', [ClarificationController::class, 'approveProposal'])->name('evidence-proposals.approve');
Route::post('/evidence-proposals/{evidenceProposal}/reject', [ClarificationController::class, 'rejectProposal'])->name('evidence-proposals.reject');
Route::patch('/career-facts/{careerFact}/revise', [ClarificationController::class, 'reviseFact'])->name('career-facts.revise');
Route::delete('/career-limitations/{careerLimitation}', [ClarificationController::class, 'revokeLimitation'])->name('career-limitations.revoke');

// Market-informed Resume Profiles: study the market, then interview. Answers
// become the same evidence proposals as job clarification (evidence-proposals.*).
Route::get('/resume-profiles', [ResumeProfileController::class, 'index'])->name('resume-profiles.index');
Route::get('/resume-profiles/{resumeProfile}', [ResumeProfileController::class, 'show'])->name('resume-profiles.show');
Route::post('/resume-profiles/{resumeProfile}/build', [ResumeProfileController::class, 'build'])->name('resume-profiles.build');
Route::post('/resume-profiles/{resumeProfile}/improve-questions', [ResumeProfileController::class, 'enrichQuestions'])->name('resume-profiles.improve-questions');
Route::post('/resume-profiles/{resumeProfile}/refresh', [ResumeProfileController::class, 'refresh'])->name('resume-profiles.refresh');
Route::post('/resume-profiles/{resumeProfile}/postings/{posting}/exclude', [ResumeProfileController::class, 'excludePosting'])->name('resume-profiles.postings.exclude');
Route::post('/resume-profiles/{resumeProfile}/postings/{posting}/restore', [ResumeProfileController::class, 'restorePosting'])->name('resume-profiles.postings.restore');
Route::post('/resume-profiles/{resumeProfile}/questions/{question}/answer', [ResumeProfileController::class, 'answer'])->name('resume-profiles.answer');
Route::post('/resume-profiles/{resumeProfile}/questions/{question}/skip', [ResumeProfileController::class, 'skip'])->name('resume-profiles.skip');
Route::post('/resume-profiles/{resumeProfile}/questions/{question}/reopen', [ResumeProfileController::class, 'reopen'])->name('resume-profiles.reopen');

// Optional expansion of one answer into separate, individually reviewable claims.
Route::post('/clarification-answers/{answer}/expand', [EvidenceExpansionController::class, 'expand'])->name('answers.expand');
Route::patch('/claim-groups/{group}', [EvidenceExpansionController::class, 'update'])->name('claim-groups.update');
Route::post('/claim-groups/{group}/approve', [EvidenceExpansionController::class, 'approve'])->name('claim-groups.approve');
Route::post('/claim-groups/{group}/reject', [EvidenceExpansionController::class, 'reject'])->name('claim-groups.reject');
Route::post('/claim-groups/{group}/merge', [EvidenceExpansionController::class, 'merge'])->name('claim-groups.merge');
Route::post('/expansions/{expansion}/followups/{index}/ask', [EvidenceExpansionController::class, 'askFollowup'])->whereNumber('index')->name('expansions.followups.ask');

// Phase E3 batch actions (Opportunity Triage): human-initiated, backend-only
// analyze/match over an explicitly selected set of job postings by id. Both
// are POST (they perform claims/dispatches), route to the single
// JobBatchController, and flash a structured result under `batch_result`
// before redirecting back. No polling, no "all filtered" mode — see
// App\Http\Controllers\JobBatchController and
// App\Support\OpportunityTriage\ProcessBatchOpportunities.
Route::post('/jobs/batch/analyze', [JobBatchController::class, 'analyze'])->name('jobs.batch.analyze');
Route::post('/jobs/batch/match', [JobBatchController::class, 'match'])->name('jobs.batch.match');

// Job Analysis generation is queued (GenerateJobAnalysisJob) — this
// POST only creates/looks up a GenerationAttempt and dispatches, so it
// no longer needs AllowLongRunningGeneration; the actual, potentially
// multi-minute Ollama call now happens in the queue worker's own
// process, not this request's. See
// docs/job-analysis-generation.md "Async Job Analysis".
Route::post('/jobs/{jobPosting}/analyses', [JobAnalysisController::class, 'store'])->name('jobs.analyses.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}', [JobAnalysisController::class, 'show'])->name('jobs.analyses.show');

// Read-only polling endpoint for a GenerationAttempt's durable status —
// see App\Http\Controllers\GenerationAttemptController.
Route::get('/generation-attempts/{generationAttempt}', [GenerationAttemptController::class, 'show'])->name('generation-attempts.show');

// "Inspect Application" — dispatches the durable inspection workflow
// (see App\Support\ApplicationInspection\DispatchApplicationInspection)
// and the read-only results page. Never calls the browser worker
// directly — see routes/api.php for the machine-only protocol surface
// this workflow's AgentRun is eventually claimed and reported through.
Route::post('/jobs/{jobPosting}/applications', [ApplicationController::class, 'store'])->name('jobs.applications.store');
Route::get('/applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
Route::get('/applications/{application}/status', [ApplicationInspectionStatusController::class, 'show'])->name('applications.status');

// Read-only worker presence feed for the nav-bar badge and
// "waiting for browser worker" states — see
// App\Support\ApplicationInspection\PresentWorkerAvailability.
Route::get('/browser-worker/status', [BrowserWorkerStatusController::class, 'show'])->name('browser-worker.status');

// Job Match generation is also queued now (GenerateJobMatchJob), same
// pattern as Job Analysis above. AllowLongRunningGeneration is kept on
// this route for this milestone even though the POST request itself no
// longer blocks on the Ollama call — see docs/job-match-generation.md
// "Async Job Match" and this middleware's own docblock.
Route::post('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches', [JobMatchController::class, 'store'])->middleware(AllowLongRunningGeneration::class)->name('jobs.analyses.matches.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}', [JobMatchController::class, 'show'])->name('jobs.analyses.matches.show');

// Resume generation is also queued now (GenerateResumeVariantJob), same
// pattern as Job Analysis/Job Match above — one attempt covers the
// entire Selection -> Wording pipeline. AllowLongRunningGeneration is
// kept on this route for this milestone even though the POST request
// itself no longer blocks on either provider call — see
// docs/resume-variant-generation.md "Async Resume" and this
// middleware's own docblock.
Route::post('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}/resume', [ResumeVariantController::class, 'store'])->middleware(AllowLongRunningGeneration::class)->name('jobs.analyses.matches.resume.store');
Route::get('/jobs/{jobPosting}/analyses/{jobAnalysis}/matches/{jobMatch}/resume/{resumeVariant}', [ResumeVariantController::class, 'show'])->name('jobs.analyses.matches.resume.show');

// Deterministic, non-Inertia print/preview surface — the single visual
// source for both browser preview and PDF export. See
// App\Http\Controllers\ResumeVariantPreviewController.
Route::get('/resume-variants/{resumeVariant}/preview', [ResumeVariantPreviewController::class, 'show'])->name('resume-variants.preview');

// PDF export of the same document — see
// App\Http\Controllers\ResumeVariantPdfController.
Route::get('/resume-variants/{resumeVariant}/pdf', [ResumeVariantPdfController::class, 'show'])->name('resume-variants.pdf');
