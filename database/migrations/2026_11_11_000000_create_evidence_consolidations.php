<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_consolidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('theme', 60);
            // revise | create | group | tag | advice
            $table->string('action', 20);
            $table->string('status', 20)->default('proposed');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('target_fact_id')->nullable();
            $table->unsignedBigInteger('target_proposal_id')->nullable();
            $table->unsignedBigInteger('source_answer_id')->nullable();
            $table->string('topic_key', 60)->nullable();
            $table->text('statement')->nullable();
            $table->json('scopes')->nullable();
            $table->string('attributable_type', 30)->nullable();
            $table->unsignedBigInteger('attributable_id')->nullable();
            $table->string('visibility', 20)->nullable();
            $table->string('verification', 30)->nullable();
            $table->string('claim_group', 36)->nullable();
            $table->json('fact_ids')->nullable();
            $table->json('skills')->nullable();
            $table->json('links')->nullable();
            $table->json('decision')->nullable();
            $table->json('notes')->nullable();
            $table->json('history')->nullable();
            $table->json('applied')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['career_profile_id', 'code']);
            $table->index(['career_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_consolidations');
    }
};
