<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable, operational application URL for an Application: where the person would actually complete the
 * application, as opposed to job_postings.source_url (where the job was discovered, never changed here). Written by a
 * person ('user', never overwritten automatically) or by the inspector once it reached a form ('resolved').
 * All nullable: every existing Application simply has none yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('application_url', 2048)->nullable()->after('status');
            $table->string('application_url_origin', 20)->nullable()->after('application_url');
            $table->timestamp('application_url_set_at')->nullable()->after('application_url_origin');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['application_url', 'application_url_origin', 'application_url_set_at']);
        });
    }
};
