<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite rebuilds a table to change a column, and dropping the old table with foreign keys on
     * would cascade into every variant's bullets, skills and citations. Keys are switched off
     * (outside a transaction, where the pragma takes effect) for the rebuild.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('resume_profile_syntheses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // draft | approved | superseded. "Stale" is derived: the evidence revision moved on.
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('evidence_revision');
            $table->foreignId('market_sample_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('pilot')->default(false);
            $table->string('input_hash', 64);
            $table->string('prompt_version', 40)->nullable();
            $table->string('generated_by', 120)->nullable();
            $table->json('usage')->nullable();
            // The evidence inventory (deterministic) and the editable presentation language (model-written).
            $table->json('content');
            // The user's include / exclude / pin / reorder / wording decisions, kept apart from `content`.
            $table->json('edits')->nullable();
            $table->json('validation')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['resume_profile_id', 'version']);
        });

        Schema::disableForeignKeyConstraints();

        Schema::table('resume_variants', function (Blueprint $table) {
            $table->foreignId('job_match_id')->nullable()->change();
            $table->foreignId('resume_profile_synthesis_id')->nullable()->after('job_match_id')->constrained('resume_profile_syntheses')->restrictOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('resume_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_profile_synthesis_id');
        });

        Schema::enableForeignKeyConstraints();
        Schema::dropIfExists('resume_profile_syntheses');
    }
};
