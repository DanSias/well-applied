<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_sample_postings', function (Blueprint $table) {
            // Why the posting fits the role family: matched title terms, front-end and back-end signals.
            $table->json('signals')->nullable()->after('decision_detail');
        });

        // A posting the user removed from the sample stays removed across rebuilds. The source
        // posting (if any) and application tracking are untouched.
        Schema::create('market_sample_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_profile_id')->constrained()->cascadeOnDelete();
            $table->string('content_hash', 64);
            $table->string('provider');
            $table->string('source_job_id')->nullable();
            $table->string('company_key');
            $table->string('title_key');
            $table->string('company');
            $table->string('title');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['resume_profile_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_sample_exclusions');
        Schema::table('market_sample_postings', fn (Blueprint $table) => $table->dropColumn('signals'));
    }
};
