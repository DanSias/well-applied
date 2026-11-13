<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Submission is a person's record that a real job application went out. It is not the browser inspection record
     * (`applications`, left exactly as it is) and is never inferred from one, from a resume, from a pursued job or from
     * a worker run: only an explicit act of recording creates a row.
     *
     * Deletion: `job_posting_id` and `application_id` RESTRICT, like `applications` itself, because a submission is
     * history that must not vanish with the posting or the inspection it was made from. `resume_variant_id` and
     * `workflow_run_id` are nullOnDelete: they are references, and the submitted PDF's own path and SHA-256 are what
     * prove what went out. A job may have any number of submissions over time (re-applying, a second requisition).
     * There is deliberately no status column: how an application stands is derived from its events.
     */
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->restrictOnDelete();
            // Provenance only: the inspection record that was current when this was recorded. Never required.
            $table->foreignId('application_id')->nullable()->constrained('applications')->restrictOnDelete();
            // The exact (immutable) variant submitted; null when the person applied with a resume made elsewhere.
            $table->foreignId('resume_variant_id')->nullable()->constrained('resume_variants')->nullOnDelete();
            // A snapshot of the link used, since a rediscovery can change the posting's own.
            $table->string('application_url', 2048)->nullable();
            $table->string('method');
            // When the person says it was submitted: their time, not the time it was recorded (created_at).
            $table->timestamp('submitted_at');
            $table->text('notes')->nullable();
            // Reserved for browser-assisted submission; nothing writes it yet.
            $table->foreignId('workflow_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
            // The exact bytes submitted, kept on the private disk, and their SHA-256. Both or neither.
            $table->string('submitted_pdf_path')->nullable();
            $table->string('submitted_pdf_sha256', 64)->nullable();
            // A retry of the same recording returns the same row instead of making a second.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['job_posting_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
