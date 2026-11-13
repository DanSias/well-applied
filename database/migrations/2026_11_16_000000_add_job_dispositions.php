<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A job's disposition (new / shortlisted / dismissed / pursued) lives on the posting; every change is also
     * appended to job_decisions, which records who decided, why, and what it replaced.
     *
     * Every existing job becomes `new` through the column default: nothing is reclassified here, including jobs that
     * already have resume or application work (see `jobs:existing-work` for a read-only report of those).
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('disposition')->default('new')->index();
            $table->string('disposition_source')->nullable();
            $table->string('disposition_reason')->nullable();
            $table->timestamp('dispositioned_at')->nullable();
            $table->timestamp('pursued_at')->nullable();
        });

        Schema::create('job_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('from_disposition');
            $table->string('to_disposition');
            $table->string('source');
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            // Which rule decided, when a rule did; null for a person.
            $table->string('rule')->nullable();
            $table->json('details')->nullable();
            // Append-only: a row is written once and never updated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_posting_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_decisions');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex(['disposition']);
            $table->dropColumn(['disposition', 'disposition_source', 'disposition_reason', 'dispositioned_at', 'pursued_at']);
        });
    }
};
