<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What happened to a submission afterwards: a confirmation, an interview, a rejection, an offer. Append-only, like
     * job_decisions: a row is written once and never updated or removed (the model enforces it), and a mistaken record
     * is corrected by a `recorded_in_error` event, not by deleting anything. `occurred_at` is when it happened and
     * `created_at` when it was recorded, so late and out-of-order entries keep their true timing.
     */
    public function up(): void
    {
        Schema::create('submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->timestamp('occurred_at');
            $table->string('source');
            $table->text('note')->nullable();
            $table->json('evidence')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['submission_id', 'idempotency_key']);
            $table->index(['submission_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_events');
    }
};
