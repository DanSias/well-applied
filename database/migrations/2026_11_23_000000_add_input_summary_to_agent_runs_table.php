<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an application_preparer attempt was handed: the resume variant and its SHA-256, the keys (never the values) of
 * the applicant data, and the hash of the private input snapshot. Known at dispatch, whereas result_summary is only
 * written when a result arrives. Null for inspections. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->json('input_summary')->nullable()->after('result_summary');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('input_summary');
        });
    }
};
