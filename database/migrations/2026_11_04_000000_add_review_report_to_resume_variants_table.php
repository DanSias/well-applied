<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The review report written when a resume variant is created: which
 * generated claims passed, were generalized, were omitted, or need
 * human verification, with the evidence behind each. Additive and
 * nullable; existing variants simply have no report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_variants', function (Blueprint $table) {
            $table->text('review_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resume_variants', function (Blueprint $table) {
            $table->dropColumn('review_report');
        });
    }
};
