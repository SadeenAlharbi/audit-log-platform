<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per POST /api/v1/logs/batch request, so the outcome of an
 * asynchronous batch can be followed after the 202 response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('api_key_id')->constrained('project_api_keys')->restrictOnDelete();

            $table->unsignedInteger('count');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->enum('status', ['queued', 'processing', 'completed', 'partially_failed', 'failed'])
                ->default('queued');
            $table->json('errors')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batches');
    }
};
