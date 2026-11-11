<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-level membership (plan §13): user + project + role.
 * role_id points to a role with scope = 'project' (Project Owner / Project
 * Viewer); the application validates the scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
