<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-click tailored resume support and performance instrumentation for
 * generation_attempts. Additive and nullable only: existing rows and the
 * existing manual stage actions are untouched.
 *
 * - pipeline_run_id / chain: attempts started by the one-click action
 *   share a run id; `chain` means "on success, advance to the next
 *   stage" (manual attempts keep chain = false and never auto-advance).
 * - phase: the live sub-stage while Running (e.g. selection, wording).
 * - *_ms, token and retry columns: provider-neutral timing and usage
 *   recorded on success (see App\Support\GenerationAttempt\
 *   GenerationMetrics). Content is never stored here.
 * - pdf_page_count / layout_*: the measured PDF composition of a
 *   resume_pdf attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->string('pipeline_run_id')->nullable()->index();
            $table->boolean('chain')->default(false);
            $table->boolean('regenerate')->default(false);
            $table->string('phase')->nullable();

            $table->unsignedInteger('queue_wait_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('selection_ms')->nullable();
            $table->unsignedInteger('wording_ms')->nullable();
            $table->unsignedInteger('render_ms')->nullable();

            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedTinyInteger('provider_calls')->nullable();
            $table->unsignedTinyInteger('wording_attempts')->nullable();

            $table->unsignedTinyInteger('pdf_page_count')->nullable();
            $table->string('layout_mode')->nullable();
            $table->text('layout_diagnostics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'pipeline_run_id', 'chain', 'regenerate', 'phase', 'queue_wait_ms', 'duration_ms', 'selection_ms',
                'wording_ms', 'render_ms', 'reasoning_tokens', 'provider_calls', 'wording_attempts',
                'pdf_page_count', 'layout_mode', 'layout_diagnostics',
            ]);
        });
    }
};
