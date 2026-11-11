<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of every backup / archive / restore operation (plan §12),
 * shown on the Backups page in Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', [
                'full',           // mariadb-backup full
                'incremental',    // mariadb-backup incremental
                'binlog',         // binary log copy (point-in-time recovery)
                'logs_archive',   // monthly audit_logs JSONL archive
                'app_files',      // spatie/laravel-backup: .env + storage
                'restore_test',   // weekly backup:restore-test
                'restore',        // real restore (full or one project)
            ]);
            $table->enum('status', ['running', 'succeeded', 'failed'])->default('running');
            $table->unsignedBigInteger('size')->nullable()->comment('bytes');
            $table->string('location', 1024)->nullable();
            $table->char('checksum', 64)->nullable()->comment('SHA-256 of the artifact');
            $table->json('meta')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['type', 'started_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
