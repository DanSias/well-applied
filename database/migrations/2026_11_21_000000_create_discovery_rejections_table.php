<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidates the global discovery filter turned away, kept so "why didn't this posting make it into Well Applied?"
     * has an answer. One row per provider identity (source + source_job_id): a candidate seen again updates the same row
     * rather than adding another. Bounded text only — never a description — and pruned 30 days after last_seen_at.
     * Nothing here is a JobPosting; a rejection never enters the Jobs inbox.
     */
    public function up(): void
    {
        Schema::create('discovery_rejections', function (Blueprint $table) {
            $table->id();

            $table->string('source');
            $table->string('source_job_id', 500);

            $table->string('title');
            $table->string('company');
            $table->string('location')->nullable();
            $table->string('remote_status')->nullable();
            $table->string('source_url', 1000)->nullable();

            $table->string('reason_code');
            $table->string('matched_term', 100)->nullable();
            $table->string('matched_field', 40)->nullable();
            $table->string('evidence_snippet', 240)->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('seen_count')->default(1);
            $table->foreignId('last_discovery_run_id')->nullable()->constrained('discovery_runs')->nullOnDelete();

            // Set when a later run accepts the same candidate: the rejection is history, not a current exclusion.
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['source', 'source_job_id']);
            $table->index(['resolved_at', 'last_seen_at']);
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_rejections');
    }
};
