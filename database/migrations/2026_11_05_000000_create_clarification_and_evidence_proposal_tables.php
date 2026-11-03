<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A monotonic counter bumped whenever approved evidence changes the
        // canonical fact corpus. A JobMatch records the value it was built
        // against, so a match is reusable only while the corpus it saw is
        // still current. Existing rows default to 0, so nothing is
        // invalidated until the first approval.
        Schema::table('career_profiles', function (Blueprint $table) {
            $table->unsignedInteger('evidence_revision')->default(0);
        });

        Schema::table('job_matches', function (Blueprint $table) {
            $table->unsignedInteger('evidence_revision')->default(0);
        });

        Schema::create('clarification_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_match_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pipeline_run_id')->nullable()->index();
            $table->string('mode')->default('standard');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['job_posting_id', 'job_analysis_id']);
        });

        Schema::create('clarification_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clarification_session_id')->constrained()->cascadeOnDelete();
            $table->string('topic_key');
            $table->string('topic_label');
            $table->foreignId('job_analysis_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->json('related_finding_ids');
            $table->text('question_text');
            $table->float('score');
            $table->json('score_breakdown');
            $table->json('context');
            $table->string('suggested_attributable_type')->nullable();
            $table->unsignedBigInteger('suggested_attributable_id')->nullable();
            $table->string('suggested_visibility');
            $table->string('status')->default('open');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['topic_key', 'status']);
        });

        // Append-only: every submission is kept verbatim, so a revised
        // answer never destroys the original wording.
        Schema::create('clarification_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clarification_question_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->json('scopes');
            $table->text('answer_text');
            $table->timestamps();
        });

        Schema::create('evidence_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clarification_answer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_analysis_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('status')->default('proposed');
            $table->string('topic_key');
            $table->string('topic_label');
            $table->text('statement');
            $table->json('scopes');
            $table->string('attributable_type')->nullable();
            $table->unsignedBigInteger('attributable_id')->nullable();
            $table->string('visibility');
            $table->string('verification');
            $table->foreignId('career_fact_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('career_limitation_id')->nullable();
            $table->unsignedBigInteger('presentation_preference_id')->nullable();
            $table->json('history');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['career_profile_id', 'status']);
            $table->index(['career_profile_id', 'topic_key']);
        });

        Schema::create('career_limitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('topic_key');
            $table->string('topic');
            $table->json('terms');
            $table->string('status')->default('no_direct_experience');
            $table->text('note')->nullable();
            $table->string('source')->default('user_confirmation');
            $table->timestamp('confirmed_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('source_job_posting_id')->nullable()->constrained('job_postings')->nullOnDelete();
            $table->unsignedBigInteger('source_answer_id')->nullable();
            $table->timestamps();

            $table->index(['career_profile_id', 'topic_key']);
        });

        Schema::create('presentation_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('topic_key');
            $table->string('topic');
            $table->text('guidance');
            $table->timestamp('confirmed_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('source_job_posting_id')->nullable()->constrained('job_postings')->nullOnDelete();
            $table->unsignedBigInteger('source_answer_id')->nullable();
            $table->timestamps();

            $table->index(['career_profile_id', 'topic_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_preferences');
        Schema::dropIfExists('career_limitations');
        Schema::dropIfExists('evidence_proposals');
        Schema::dropIfExists('clarification_answers');
        Schema::dropIfExists('clarification_questions');
        Schema::dropIfExists('clarification_sessions');

        Schema::table('job_matches', fn (Blueprint $table) => $table->dropColumn('evidence_revision'));
        Schema::table('career_profiles', fn (Blueprint $table) => $table->dropColumn('evidence_revision'));
    }
};
