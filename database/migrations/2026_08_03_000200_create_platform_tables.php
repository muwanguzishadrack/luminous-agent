<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group 1 remainder: audit_logs + admin_sessions (docs/02-data-model.md §1).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('team_id')->index();
            $table->string('actor_type'); // user | system | mba | owner_device
            $table->uuid('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->index();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->foreignUuid('admin_user_id')->constrained('users');
            $table->string('reason');
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
        Schema::dropIfExists('audit_logs');
    }
};
