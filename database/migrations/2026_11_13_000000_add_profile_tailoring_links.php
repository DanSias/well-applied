<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile-assisted job tailoring: a resume composed from an approved profile for one job is linked to the job's analysis
 * (there is no candidate match in this path), and every attempt of such a run carries the approved profile version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('resume_variants', function (Blueprint $table) {
            $table->foreignId('job_analysis_id')->nullable()->after('job_match_id')->constrained('job_analyses')->restrictOnDelete();
        });

        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->foreignId('resume_profile_synthesis_id')->nullable()->constrained('resume_profile_syntheses')->restrictOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('generation_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resume_profile_synthesis_id');
        });

        Schema::table('resume_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_analysis_id');
        });

        Schema::enableForeignKeyConstraints();
    }
};
