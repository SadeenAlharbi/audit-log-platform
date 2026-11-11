<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every rejected API request (plan §7): bad/expired/revoked key, bad
 * signature, replay, IP not allowed, rate limited...
 * project_id / api_key_id are null when the key could not be identified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('project_api_keys')->restrictOnDelete();
            $table->string('ip', 45);
            $table->string('reason', 64);
            $table->json('request_meta')->nullable();
            $table->dateTime('created_at', precision: 3)->useCurrent();

            $table->index('created_at');
            $table->index(['ip', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
