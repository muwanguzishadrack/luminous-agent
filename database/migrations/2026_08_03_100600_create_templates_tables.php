<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 6 — Templates (docs/02-data-model.md §6).
 *
 * Tables: template_group, templates, template_events.
 * (template_group created first so templates.template_group_id can be a real FK.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Logical multi-language set — lets a campaign target a group and
        // resolve the right language per contact.
        Schema::create('template_group', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('key');
            $table->string('name');
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('waba_account_id')->index(); // group 2 — plain uuid
            $table->foreignUuid('template_group_id')->nullable()->constrained('template_group');
            $table->string('meta_template_id')->nullable(); // null until submitted
            $table->string('name'); // Meta's snake_case name
            $table->string('language'); // e.g. en, en_US
            $table->string('category'); // enum: MARKETING|UTILITY|AUTHENTICATION (app-level)
            $table->string('sub_type')->nullable(); // carousel|coupon_code|limited_time_offer|catalog|mpm|spm|location|otp|call_permission_request
            $table->string('status'); // DRAFT|PENDING|APPROVED|REJECTED|PAUSED|DISABLED|IN_APPEAL
            $table->string('quality_score')->nullable(); // GREEN|YELLOW|RED
            $table->string('rejected_reason')->nullable(); // surfaced verbatim in the UI
            $table->jsonb('components'); // header/body/footer/buttons as Meta expects
            $table->jsonb('variable_map');
            $table->integer('ttl_seconds')->nullable(); // time-to-live override
            $table->string('library_template_name')->nullable(); // when created from Meta's Template Library
            $table->timestampTz('paused_until')->nullable();
            $table->timestampTz('last_synced_at')->nullable(); // nullable: DRAFT templates have never synced
            $table->softDeletesTz();

            $table->unique(['waba_account_id', 'name', 'language']);
        });

        // Append-only, from message_template_status_update / _quality_update /
        // _components_update / template_category_update webhooks.
        Schema::create('template_events', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('template_id')->constrained('templates');
            $table->index('template_id');
            $table->string('event');
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('reason')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_events');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('template_group');
    }
};
