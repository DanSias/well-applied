<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Application Readiness Review of one exact, immutable resume variant against its job. The review records what it
 * was made from (job description, analysis, the rendered document and the review prompt) so it can be recognized as out
 * of date, and is never edited into a different review: a new run is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_readiness_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_analysis_id')->constrained('job_analyses')->restrictOnDelete();
            $table->string('status', 16);
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('phase', 24)->nullable();
            $table->string('description_fingerprint', 64);
            $table->string('analysis_fingerprint', 64)->nullable();
            $table->string('artifact_fingerprint', 64)->nullable();
            $table->string('prompt_version', 48);
            $table->string('model', 96)->nullable();
            $table->json('result')->nullable();
            $table->json('metrics')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(['resume_variant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_readiness_reviews');
    }
};
