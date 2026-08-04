<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 7 — Campaigns (docs/02-data-model.md §7).
 *
 * Tables: campaigns, campaign_recipients, campaign_clicks,
 * sequences, sequence_steps, sequence_enrollments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('phone_number_id')->index(); // group 2 — plain uuid
            $table->string('name');
            // Doc says "template_id or template_group_id" — modelled as two nullable
            // columns; exactly one is set (enforced at app level).
            $table->uuid('template_id')->nullable()->index(); // group 6 — plain uuid
            $table->uuid('template_group_id')->nullable()->index(); // group 6 — plain uuid
            $table->uuid('segment_id')->nullable()->index(); // null when a static list is uploaded
            $table->string('routing'); // enum: cloud_api|mm_api (app-level)
            $table->string('product_policy')->nullable(); // CLOUD_API_FALLBACK|STRICT — MM API only
            $table->string('status'); // draft|scheduled|queueing|sending|paused|completed|cancelled|failed
            $table->timestampTz('scheduled_for')->nullable();
            $table->string('timezone_mode'); // enum: fixed|recipient_local (app-level)
            $table->bigInteger('budget_cap_minor')->nullable(); // hard stop
            $table->bigInteger('spent_minor')->default(0); // running total
            $table->uuid('variant_group_id')->nullable(); // A/B parent
            $table->smallInteger('variant_weight')->nullable();
            $table->jsonb('stats')->default('{}'); // denormalised counters
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->softDeletesTz();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('team_id')->index();
            $table->foreignUuid('campaign_id')->constrained('campaigns');
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->uuid('message_id')->nullable()->index(); // group 5 — plain uuid
            $table->string('wamid')->nullable();
            $table->string('status'); // pending|suppressed|queued|sent|delivered|read|clicked|replied|failed
            $table->string('suppression_reason')->nullable(); // no_consent|per_user_cap|blocked|invalid_number|missing_variable|unsupported_language|budget|duplicate
            $table->integer('error_code')->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->jsonb('variables'); // resolved variable values (audit / reproducibility)
            // Doc lists "queued_at / sent_at / …" — expanded to match the status ladder.
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('clicked_at')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->unique(['campaign_id', 'contact_id']); // guarantees no double-send
        });

        // Per-contact click tracking for wrapped URL buttons (M4 §7).
        Schema::create('campaign_clicks', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('team_id')->index();
            $table->foreignUuid('campaign_id')->constrained('campaigns');
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->smallInteger('button_index'); // which URL button
            $table->string('token')->unique(); // the /c/{token} value
            $table->text('target_url'); // resolved destination
            $table->timestampTz('clicked_at')->nullable(); // null until first click
            $table->integer('click_count')->default(0); // subsequent clicks increment
            $table->string('user_agent')->nullable();
            $table->string('ip_hash')->nullable();

            $table->unique(['campaign_id', 'contact_id', 'button_index']);
        });

        // Doc §7 defines sequences/* tersely ("Drip journeys") — expanded minimally.
        Schema::create('sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->string('name');
            $table->string('status'); // enum: draft|active|paused|archived (app-level)
            $table->jsonb('settings')->default('{}');
        });

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->foreignUuid('sequence_id')->constrained('sequences');
            $table->smallInteger('position');
            $table->string('kind'); // send_template|wait|branch|tag|assign|exit_if_replied|exit_if_ai_resolved|webhook
            $table->jsonb('config'); // step parameters (template id, wait duration, branch conditions, ...)

            $table->unique(['sequence_id', 'position']);
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->foreignUuid('sequence_id')->constrained('sequences');
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->uuid('current_step_id')->nullable();
            $table->string('status'); // enum: active|completed|exited|failed (app-level)
            $table->timestampTz('enrolled_at');
            $table->timestampTz('next_run_at')->nullable()->index();
            $table->timestampTz('exited_at')->nullable();
            $table->string('exit_reason')->nullable();

            $table->index(['sequence_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('campaign_clicks');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
