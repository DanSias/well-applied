<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The calendar date a person entered for an update, as a plain DATE, for the same reason submissions have
     * `submitted_on`: people know the day something happened, not the time, and a day stored as an instant reads as a
     * different day in another timezone. `occurred_at` stays as the instant used for ordering and the existing rules.
     * Nullable and never back-filled: older events show the date of `occurred_at` in the application's timezone.
     */
    public function up(): void
    {
        Schema::table('submission_events', function (Blueprint $table) {
            $table->date('occurred_on')->nullable()->after('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('submission_events', function (Blueprint $table) {
            $table->dropColumn('occurred_on');
        });
    }
};
