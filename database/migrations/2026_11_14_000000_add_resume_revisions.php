<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copy-on-write resume revisions. A saved edit is a new ResumeVariant that points at the variant it was made from and
 * carries a compact edit log; the original is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('resume_variants', function (Blueprint $table) {
            $table->foreignId('parent_variant_id')->nullable()->constrained('resume_variants')->nullOnDelete();
            $table->json('edit_log')->nullable();
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('resume_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_variant_id');
            $table->dropColumn('edit_log');
        });

        Schema::enableForeignKeyConstraints();
    }
};
