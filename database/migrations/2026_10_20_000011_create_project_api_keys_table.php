<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys per project (plan §5, §7).
 *
 * - The plain key is shown once; only its SHA-256 hash is stored.
 * - hmac_secret must be readable by the server to verify X-Signature, so it
 *   cannot be hashed: it is stored encrypted with APP_KEY (encrypted cast).
 * - Keys are revoked, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('name');
            $table->enum('environment', ['production', 'staging'])->default('production');

            $table->char('key_prefix', 8);
            $table->char('key_hash', 64)->unique();
            $table->text('hmac_secret')->nullable();

            $table->json('scopes');
            $table->json('allowed_ips')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'revoked_at']);
            $table->index('key_prefix');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_api_keys');
    }
};
