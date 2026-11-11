<?php

use App\Support\Database\AppendOnlyTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform auditing itself (plan §13 Self-Audit): every change to roles,
 * users, projects, API keys or backup settings. Fully immutable - not even
 * the archiver may delete from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 191)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('created_at', precision: 3)->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['action', 'created_at']);
        });

        AppendOnlyTriggers::create('platform_audit');
    }

    public function down(): void
    {
        AppendOnlyTriggers::drop('platform_audit');
        Schema::dropIfExists('platform_audit');
    }
};
