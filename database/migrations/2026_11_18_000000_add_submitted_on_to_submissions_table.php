<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The calendar date a person entered, kept as a plain DATE. Most people know the day they applied, not the time, so
     * the day is the fact; `submitted_at` stays as the instant used to order things (and for submissions that are one day
     * made by a machine). A date is never converted through a timezone, so it reads the same wherever it is viewed.
     *
     * Nullable and not back-filled: a row recorded before this column has only `submitted_at`, which was saved as the
     * start of the entered day in a timezone that was never stored, so the original date cannot be recovered reliably.
     * Those rows show the date of `submitted_at` in the application's timezone (see Submission::displayDate()); nothing
     * existing is rewritten.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->date('submitted_on')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('submitted_on');
        });
    }
};
