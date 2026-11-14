<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive stage counts. Both are null on attempts recorded before this change and on attempts whose provider could not
     * observe the stage: null means "not recorded", never zero. Existing counters keep their meaning.
     *
     * rejection_counts: global-filter rejections by reason code. Sums to candidates_retrieved - candidates_accepted.
     * retrieval_diagnostics: what the provider saw before the global filter (see ReportsRetrievalDiagnostics).
     */
    public function up(): void
    {
        Schema::table('discovery_provider_attempts', function (Blueprint $table) {
            $table->json('rejection_counts')->nullable()->after('jobs_canonicalized');
            $table->json('retrieval_diagnostics')->nullable()->after('rejection_counts');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_provider_attempts', function (Blueprint $table) {
            $table->dropColumn(['rejection_counts', 'retrieval_diagnostics']);
        });
    }
};
