<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reliability support for resume generation. Additive only: existing
 * attempts, matches and variants are untouched.
 *
 * - generation_attempts.retry_log: the per-call events of one attempt
 *   (validation rejections with their reasons, empty model outputs,
 *   checkpoint reuse). Never stores prompts, model output or reasoning.
 * - generation_attempts.selection_reused: Selection was served from a
 *   checkpoint instead of a provider call.
 * - resume_selection_checkpoints: a validated Selection response, keyed
 *   by a fingerprint of everything that determined it, so a failure in
 *   a later stage does not repeat the (slow) Selection call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->text('retry_log')->nullable();
            $table->boolean('selection_reused')->default(false);
        });

        Schema::create('resume_selection_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_match_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('prompt_version');
            $table->string('schema_version');
            $table->string('provider');
            $table->string('model');
            $table->text('structured_content');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['job_match_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_selection_checkpoints');

        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->dropColumn(['retry_log', 'selection_reused']);
        });
    }
};
