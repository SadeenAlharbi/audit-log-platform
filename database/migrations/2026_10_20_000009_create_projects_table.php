<?php

use App\Support\Database\AppendOnlyTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A system that sends logs (tenant).
 *
 * audit_logs cannot reference projects with a foreign key because it is
 * partitioned (decision D1). To keep every log attached to an existing
 * project, projects are never deleted: they are archived (archived_at), and a
 * trigger rejects DELETE at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            // D3: per-project retention, capped by config('audit.retention.max_days').
            $table->unsignedSmallInteger('retention_days')->default(365);

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'archived_at']);
        });

        DB::statement(
            'ALTER TABLE projects ADD CONSTRAINT projects_retention_days_range CHECK (retention_days BETWEEN 1 AND 3650)'
        );

        AppendOnlyTriggers::preventDelete(
            'projects',
            'projects cannot be deleted (audit_logs reference them without a FK): set archived_at instead',
        );
    }

    public function down(): void
    {
        AppendOnlyTriggers::dropPreventDelete('projects');
        Schema::dropIfExists('projects');
    }
};
