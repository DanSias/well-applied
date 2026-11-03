<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One model-assisted reading of one answer: cached by fingerprint, with timing and usage.
        Schema::create('evidence_expansions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clarification_answer_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('ready');
            $table->string('fingerprint', 64);
            $table->string('prompt_version');
            $table->unsignedInteger('evidence_revision')->default(0);
            $table->string('model')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('usage')->nullable();
            // What the model returned, as validated: accepted claims, rejected ones with reasons, follow-ups.
            $table->json('result')->nullable();
            $table->string('failure')->nullable();
            $table->timestamps();

            $table->index(['clarification_answer_id', 'fingerprint']);
        });

        // A proposal produced by an expansion keeps its source answer (already on the row), the
        // exact quote that supports it, and the claim it belongs to. One claim spanning several
        // projects is several proposals sharing `claim_group`, each reviewed under the existing rules.
        Schema::table('evidence_proposals', function (Blueprint $table) {
            $table->foreignId('expansion_id')->nullable()->after('clarification_answer_id')->constrained('evidence_expansions')->nullOnDelete();
            $table->string('claim_group', 36)->nullable()->index();
            $table->text('source_quote')->nullable();
            $table->string('confidence')->nullable();
            $table->text('rationale')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('evidence_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expansion_id');
            $table->dropColumn(['claim_group', 'source_quote', 'confidence', 'rationale']);
        });
        Schema::dropIfExists('evidence_expansions');
    }
};
