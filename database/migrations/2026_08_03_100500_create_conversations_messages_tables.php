<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group 5 — Conversations & messages (docs/02-data-model.md §5).
 *
 * Tables: conversations, media, messages, message_events,
 * thread_control_events, canned_replies.
 * (media created before messages so messages.media_id can be a real FK.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('phone_number_id')->index(); // group 2 — plain uuid
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->string('state'); // enum: ai|queued|human|closed (app-level)
            $table->string('owner_app_id')->nullable(); // from messaging_handovers
            $table->uuid('assigned_user_id')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('csw_expires_at')->nullable(); // 24h customer service window
            $table->timestampTz('fep_expires_at')->nullable(); // 72h free entry point window
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();
            $table->integer('unread_count')->default(0);
            $table->timestampTz('first_response_at')->nullable(); // for FRT reporting
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('sla_breached_at')->nullable();
            $table->integer('ai_handled_count')->default(0); // containment-rate reporting
            $table->integer('human_handled_count')->default(0);

            $table->unique(['tenant_id', 'phone_number_id', 'contact_id']);
            $table->index(['assigned_user_id', 'state']);
            $table->index('csw_expires_at');
        });

        DB::statement('CREATE INDEX conversations_tenant_state_last_message_idx ON conversations (tenant_id, state, last_message_at DESC)');

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('meta_media_id')->nullable(); // Meta's id; expires ~30 days
            $table->char('sha256', 64)->index(); // dedupe identical uploads
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->string('filename')->nullable();
            $table->string('disk'); // our S3/MinIO copy
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->integer('duration_ms')->nullable(); // audio/video
            $table->text('transcript')->nullable(); // voice-note STT
            $table->string('scan_status'); // enum: pending|clean|infected|skipped
            $table->timestampTz('meta_expires_at')->nullable(); // re-upload before this to reuse
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('conversation_id')->constrained('conversations');
            $table->string('wamid')->nullable()->unique(); // idempotency key; nullable until Meta assigns it on outbound send
            $table->string('direction'); // enum: inbound|outbound
            $table->string('type'); // text|image|audio|...|template|system|unsupported
            $table->text('body')->nullable(); // extracted plain text for search (Meilisearch)
            $table->jsonb('payload'); // full message object as sent/received
            $table->foreignUuid('media_id')->nullable()->constrained('media');
            $table->string('replied_to_wamid')->nullable(); // contextual reply
            $table->string('reaction_to_wamid')->nullable();
            $table->string('origin'); // agent|mba|campaign|automation|owner_device|customer|system
            $table->uuid('sent_by_user_id')->nullable();
            $table->uuid('campaign_id')->nullable(); // group 7 — plain uuid
            $table->uuid('template_id')->nullable(); // group 6 — plain uuid
            $table->string('status'); // enum: queued|sent|delivered|read|failed|deleted
            $table->integer('error_code')->nullable(); // Meta error code
            $table->jsonb('error_detail')->nullable();
            $table->string('pricing_category')->nullable(); // marketing|utility|authentication|service|meta_business_agent
            $table->boolean('billable')->nullable();
            $table->bigInteger('cost_minor')->nullable(); // resolved by the meter, may be backfilled
            $table->integer('token_count')->nullable(); // MBA messages only
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('occurred_at')->index(); // canonical sort key (Meta timestamp for inbound)

            $table->index(['conversation_id', 'occurred_at']);
            $table->index('campaign_id');
        });

        DB::statement('CREATE INDEX messages_tenant_occurred_idx ON messages (tenant_id, occurred_at DESC)');
        DB::statement("CREATE INDEX messages_status_partial_idx ON messages (status) WHERE status IN ('queued', 'sent')");

        // Append-only status ladder — lets a `read` arriving before `delivered` be recorded honestly.
        Schema::create('message_events', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('message_id')->constrained('messages');
            $table->index('message_id');
            $table->string('wamid');
            $table->string('status');
            $table->integer('error_code')->nullable();
            $table->jsonb('pricing')->nullable();
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
        });

        Schema::create('thread_control_events', function (Blueprint $table) {
            $table->id(); // append-only: bigint pk
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('conversation_id')->constrained('conversations');
            $table->index('conversation_id');
            $table->string('event'); // enum: pass|take|request|app_roles (app-level)
            $table->string('previous_owner_app_id')->nullable();
            $table->string('new_owner_app_id')->nullable();
            $table->jsonb('metadata');
            $table->string('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('occurred_at');
        });

        Schema::create('canned_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('shortcut');
            $table->string('title');
            $table->text('body');
            $table->jsonb('variables');
            $table->boolean('is_shared')->default(false);
            $table->uuid('created_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('thread_control_events');
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('media');
        Schema::dropIfExists('conversations');
    }
};
