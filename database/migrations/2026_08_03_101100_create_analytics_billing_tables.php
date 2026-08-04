<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 11 — Analytics, billing & health (docs/02-data-model.md §11).
 *
 * Tables: usage_meters, rate_cards, wallet_entries, analytics_snapshots, health_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Append-only, the billing source of truth. Rows are never re-marked in place (D-012).
        Schema::create('usage_meters', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('team_id')->index();
            $table->uuid('waba_account_id')->nullable(); // group 2 — plain uuid
            $table->uuid('phone_number_id')->nullable(); // group 2 — plain uuid
            $table->string('meter'); // template_message|service_message|mba_tokens|platform_seat|payment_fee
            $table->string('category')->nullable(); // marketing|utility|authentication|service
            $table->char('country', 2)->nullable(); // rate varies by recipient country
            $table->bigInteger('quantity'); // messages, or tokens
            $table->bigInteger('unit_cost_micros')->nullable(); // Meta's cost per unit x10^6
            $table->bigInteger('cost_minor'); // our computed cost
            $table->bigInteger('markup_minor'); // our margin
            $table->char('currency', 3);
            $table->string('source'); // enum: webhook|pricing_analytics|mba_analytics|computed (app-level)
            $table->string('basis'); // enum: estimate|actual|correction (app-level)
            $table->date('occurred_on')->index(); // team WABA timezone day
            $table->uuid('message_id')->nullable(); // traceability
            $table->uuid('campaign_id')->nullable(); // traceability
            $table->timestampTz('created_at');

            $table->index(['team_id', 'occurred_on', 'meter']);
        });

        // Versioned Meta rate card (M8 §2). Global — no team_id, no RLS.
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('effective_from');
            $table->string('region'); // country code or Meta market grouping
            $table->string('category'); // marketing|utility|authentication|service|mba_tokens
            $table->bigInteger('tier_min')->nullable(); // volume tier bounds; null = untiered
            $table->bigInteger('tier_max')->nullable();
            $table->bigInteger('unit_cost_micros'); // USD x10^6 per message (or per token for mba_tokens)
            $table->text('source_url'); // where the rate was published
            $table->timestampTz('created_at');

            $table->unique(['effective_from', 'region', 'category', 'tier_min']);
        });

        // Team billing ledger, append-only. Balance is always SUM(amount_minor).
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('team_id')->index();
            $table->string('kind'); // enum: topup|charge|adjustment|refund (app-level)
            $table->bigInteger('amount_minor'); // signed
            $table->char('currency', 3);
            $table->bigInteger('balance_after_minor'); // cached checkpoint, never trusted alone
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable(); // string: references both uuid and bigint tables
            $table->string('description')->nullable();
            $table->timestampTz('created_at');
        });

        // Cached pulls from Meta so dashboards do not hammer the Graph API.
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('waba_account_id'); // group 2 — plain uuid
            $table->string('field'); // analytics|conversation_analytics|pricing_analytics|template_analytics|template_group_analytics
            $table->string('granularity');
            $table->timestampTz('start_at');
            $table->timestampTz('end_at');
            $table->jsonb('dimensions');
            $table->char('dimensions_hash', 64)->index(); // sha256 of canonicalised dimensions json, computed in the model
            $table->jsonb('payload');
            $table->timestampTz('fetched_at');

            $table->unique(
                ['waba_account_id', 'field', 'granularity', 'start_at', 'end_at', 'dimensions_hash'],
                'analytics_snapshots_lookup_unique'
            );
        });

        Schema::create('health_events', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('team_id')->index();
            $table->uuid('phone_number_id')->nullable(); // group 2 — plain uuid
            $table->string('kind');
            $table->string('severity'); // enum: info|warning|critical (app-level)
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_events');
        Schema::dropIfExists('analytics_snapshots');
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('usage_meters');
    }
};
