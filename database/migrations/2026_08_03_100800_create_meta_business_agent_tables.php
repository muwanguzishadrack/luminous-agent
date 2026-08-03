<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 8 — Meta Business Agent (docs/02-data-model.md §8).
 *
 * Tables: mba_agents, mba_allowlist_entries, mba_knowledge_sources,
 * connector_tokens, mba_connectors, mba_connector_tools, mba_events, mba_evals.
 * (connector_tokens created before mba_connectors for the token_id FK.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mba_agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('phone_number_id')->unique(); // group 2 — plain uuid; one agent per number
            $table->jsonb('eligibility'); // last Eligibility response + checked_at
            $table->string('vertical')->nullable(); // one of Meta's 5 approved verticals
            $table->timestampTz('tos_client_accepted_at')->nullable(); // client accepted in WhatsApp Manager
            $table->timestampTz('onboarded_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->jsonb('settings')->default('{}'); // persona, language, tone, handoff policy, followup policy
            $table->jsonb('skills'); // system instructions
            $table->string('allowlist_mode'); // enum: off|allowlist_only (app-level)
            $table->timestampTz('last_synced_at')->nullable(); // nullable: unset until first sync
        });

        Schema::create('mba_allowlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('mba_agent_id')->constrained('mba_agents');
            $table->string('wa_id');
            $table->uuid('added_by')->nullable();
            $table->timestampTz('added_at');
            $table->timestampTz('removed_at')->nullable();

            $table->index(['mba_agent_id', 'wa_id']);
        });

        Schema::create('mba_knowledge_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('mba_agent_id')->constrained('mba_agents');
            $table->string('kind'); // enum: business_info|faq|website|file (app-level)
            $table->string('external_id')->nullable(); // Meta's id for the source
            $table->jsonb('payload'); // our source of truth (question/answer, url, business fields)
            $table->uuid('media_id')->nullable(); // group 5 media — plain uuid; for kind=file
            $table->text('url')->nullable(); // for kind=website
            $table->integer('recrawl_interval_hours')->nullable();
            $table->string('sync_status'); // enum: pending|synced|failed (app-level)
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('version')->default(1); // bump to force re-push
        });

        // Bearer tokens Meta uses to call us. Store only a hash.
        Schema::create('connector_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('token_hash');
            $table->string('prefix');
            $table->jsonb('abilities');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
        });

        Schema::create('mba_connectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('mba_agent_id')->constrained('mba_agents');
            $table->string('external_id')->nullable(); // Meta's connector id
            $table->string('name');
            $table->string('base_url'); // our own connector endpoints, per-tenant
            $table->string('auth_scheme');
            $table->foreignUuid('token_id')->constrained('connector_tokens');
            $table->boolean('enabled')->default(false);
        });

        // Doc §8 lists no tenant_id on mba_connector_tools — scoping flows through the connector.
        Schema::create('mba_connector_tools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connector_id')->constrained('mba_connectors');
            $table->index('connector_id');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('method');
            $table->string('path');
            $table->jsonb('input_schema');
            $table->jsonb('output_schema');
            $table->boolean('is_write')->default(false);
            $table->boolean('enabled')->default(true);
        });

        // Agent Events we push to Meta.
        Schema::create('mba_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('conversation_id')->index(); // group 5 — plain uuid
            $table->string('kind');
            $table->jsonb('payload');
            $table->string('external_id')->nullable();
            $table->string('status');
            $table->timestampTz('sent_at')->nullable();
            $table->jsonb('error')->nullable(); // doc is terse on type; jsonb matches other error columns
        });

        Schema::create('mba_evals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('mba_agent_id')->constrained('mba_agents');
            $table->string('kind'); // enum: test|eval (app-level)
            $table->jsonb('request');
            $table->jsonb('result');
            $table->decimal('score', 8, 4)->nullable(); // doc says numeric
            $table->timestampTz('run_at');
            $table->uuid('run_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mba_evals');
        Schema::dropIfExists('mba_events');
        Schema::dropIfExists('mba_connector_tools');
        Schema::dropIfExists('mba_connectors');
        Schema::dropIfExists('connector_tokens');
        Schema::dropIfExists('mba_knowledge_sources');
        Schema::dropIfExists('mba_allowlist_entries');
        Schema::dropIfExists('mba_agents');
    }
};
