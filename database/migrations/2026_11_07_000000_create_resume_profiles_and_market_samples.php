<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('role_family');
            $table->text('purpose')->nullable();
            // Optional overrides of the configured search (terms, seniority, location).
            $table->json('search_parameters')->nullable();
            $table->string('status')->default('draft');
            // Bumped whenever the profile's own content changes (new sample, new ranking).
            $table->unsignedInteger('revision')->default(1);
            // The sample currently in force. No foreign key: samples reference the profile.
            $table->unsignedBigInteger('market_sample_id')->nullable();
            // The career evidence revision the stored coverage and ranking were computed against.
            $table->unsignedInteger('evidence_revision')->default(0);
            // Emphasis guidance specific to this profile. Guidance only, never a claim.
            $table->text('presentation_guidance')->nullable();
            $table->timestamps();

            $table->unique(['career_profile_id', 'slug']);
        });

        Schema::create('market_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_profile_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('collecting');
            $table->boolean('pilot')->default(false);
            $table->unsignedSmallInteger('target_size');
            $table->string('extraction_version');
            // Per-provider fetch log: provider, status, retrieved, error, at.
            $table->json('provider_log')->nullable();
            // Counts by decision reason, so exclusions are explainable at a glance.
            $table->json('decision_counts')->nullable();
            // The aggregated capability map (see AggregateMarketRequirements).
            $table->json('aggregate')->nullable();
            $table->timestamp('aggregated_at')->nullable();
            $table->string('failure')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('market_sample_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_sample_id')->constrained()->cascadeOnDelete();
            // Set when an already stored posting was reused. Sampling never creates job_postings.
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('source_job_id')->nullable();
            $table->text('source_url')->nullable();
            $table->string('company');
            $table->string('title');
            $table->string('location')->nullable();
            $table->string('remote_status')->nullable();
            $table->string('seniority')->nullable();
            $table->string('primary_stack')->nullable();
            $table->string('description_completeness');
            $table->longText('description')->nullable();
            $table->string('content_hash', 64);
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('retrieved_at');
            $table->boolean('included')->default(false);
            $table->string('decision');
            $table->text('decision_detail')->nullable();
            $table->unsignedBigInteger('duplicate_of_id')->nullable();
            $table->string('extraction_version')->nullable();
            $table->json('extraction')->nullable();
            $table->timestamps();

            $table->index(['market_sample_id', 'included']);
            $table->index(['content_hash', 'extraction_version']);
        });

        // Interview sessions for a profile have no job posting or analysis.
        Schema::table('clarification_sessions', function (Blueprint $table) {
            $table->foreignId('job_posting_id')->nullable()->change();
            $table->foreignId('job_analysis_id')->nullable()->change();
            $table->foreignId('career_profile_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_profile_id')->nullable()->after('career_profile_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clarification_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_profile_id');
            $table->dropConstrainedForeignId('career_profile_id');
        });
        Schema::dropIfExists('market_sample_postings');
        Schema::dropIfExists('market_samples');
        Schema::dropIfExists('resume_profiles');
    }
};
