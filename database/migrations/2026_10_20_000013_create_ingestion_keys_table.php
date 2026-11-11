<?php

use App\Support\Database\AppendOnlyTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency registry (decisions D2 + D4).
 *
 * audit_logs is partitioned, so a UNIQUE index there must include occurred_at
 * and would not stop the same external_id arriving with a different
 * occurred_at. This small, non-partitioned table enforces
 * UNIQUE(project_id, external_id) for real.
 *
 * The API inserts here synchronously BEFORE queueing the log, so a duplicate
 * is detected while the client is still waiting and gets a `duplicate`
 * response instead of 202.
 *
 * Append-only: rows are removed only by the archiver, together with the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('external_id', 191);

            // Public id of the accepted log (audit_logs.uid) - returned again
            // to a client that retries the same external_id.
            $table->char('log_uid', 26);
            $table->dateTime('occurred_at', precision: 3);
            $table->dateTime('created_at', precision: 3)->useCurrent();

            $table->unique(['project_id', 'external_id']);
            $table->index('log_uid');
            $table->index('occurred_at');
        });

        AppendOnlyTriggers::create('ingestion_keys', deleteAllowedFor: config('audit.archiver.db_user'));
    }

    public function down(): void
    {
        AppendOnlyTriggers::drop('ingestion_keys');
        Schema::dropIfExists('ingestion_keys');
    }
};
