<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Schema contract: docs/02-data-model.md §1. One team per user, enforced
     * by the unique index on team_user.user_id (D-020): the single membership
     * row *is* the team context, so there is no pointer on users to disagree
     * with it.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('onboarding');
            $table->string('plan')->nullable();
            $table->char('country', 2)->default('UG');
            $table->char('default_currency', 3)->default('UGX');
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('agent');
            $table->string('status')->default('active');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            // One team per user (D-020). A second membership cannot be created
            // by an invitation, an import, or a race — the database refuses it.
            $table->unique('user_id');
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->foreignUuid('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
