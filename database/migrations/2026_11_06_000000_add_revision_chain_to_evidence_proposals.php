<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Approved evidence is revised, never overwritten: each revision is its
     * own EvidenceProposal pointing at the one it replaced. The original
     * answer stays on the chain, and the CareerFact keeps its stable key.
     */
    public function up(): void
    {
        Schema::table('evidence_proposals', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('status');
            $table->foreignId('revises_proposal_id')->nullable()->after('revision')->constrained('evidence_proposals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidence_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revises_proposal_id');
            $table->dropColumn('revision');
        });
    }
};
