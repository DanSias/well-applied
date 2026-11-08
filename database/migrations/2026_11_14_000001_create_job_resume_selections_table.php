<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which tailored resume of a job the user is working on, and which (if any) they marked final. A variant is immutable,
 * so it cannot hold this, and the posting holds no resume-generation state, so it lives in a small table of its own.
 *
 * An earlier draft of the revisions migration put these two columns on `job_postings`; where that ran, they are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['current_resume_variant_id', 'preferred_resume_variant_id'] as $column) {
            if (Schema::hasColumn('job_postings', $column)) {
                Schema::table('job_postings', fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
            }
        }

        Schema::create('job_resume_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('current_resume_variant_id')->nullable()->constrained('resume_variants')->nullOnDelete();
            $table->foreignId('preferred_resume_variant_id')->nullable()->constrained('resume_variants')->nullOnDelete();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('job_resume_selections');
    }
};
