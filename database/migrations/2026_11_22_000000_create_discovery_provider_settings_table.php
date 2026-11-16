<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per discovery provider, holding only the scheduling override chosen on the Sources page. A missing row
     * means the code-defined default, so nothing needs seeding and nothing here describes configuration or credentials.
     * Deliberately not a generic settings table: it holds exactly one thing per provider.
     */
    public function up(): void
    {
        Schema::create('discovery_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('schedule_override')->default('default');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_provider_settings');
    }
};
