<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shared claim identity: the CareerFacts that are one underlying claim attached to several
     * places (projects or roles) carry the same `claim_group`. Null for every other fact.
     */
    public function up(): void
    {
        Schema::table('career_facts', function (Blueprint $table) {
            $table->string('claim_group', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('career_facts', fn (Blueprint $table) => $table->dropColumn('claim_group'));
    }
};
