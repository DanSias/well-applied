<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A qualification is an immutable snapshot, like JobAnalysis: a changed posting or a new policy writes a new row and the
     * older ones stay as history. The fingerprints say what the snapshot was made from, so freshness is a comparison, not a
     * flag that someone has to remember to flip.
     */
    public function up(): void
    {
        Schema::create('job_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            // Qualification depends on the person's evidence, so it belongs to a profile as well as a job.
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();

            $table->string('content_fingerprint', 64);
            $table->string('profile_fingerprint', 64);
            $table->string('policy_version');
            // Null on a deterministic-only result (the eligibility gate stopped it before any model was asked).
            $table->string('prompt_version')->nullable();
            $table->string('schema_version')->nullable();
            $table->string('generated_by')->nullable();

            $table->string('eligibility');
            $table->json('eligibility_reasons');

            // Null when no model was asked: an ineligible or under-described job has no recommendation to give.
            $table->string('verdict')->nullable();
            // What the model said before the policy check adjusted it, kept so the adjustment is visible.
            $table->string('model_verdict')->nullable();
            $table->json('assessment')->nullable();
            $table->json('adjustments')->nullable();

            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['job_posting_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_qualifications');
    }
};
