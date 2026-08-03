<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 10 — Ads that Click to WhatsApp (docs/02-data-model.md §10).
 *
 * Tables: ctwa_referrals, conversions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ctwa_referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->uuid('conversation_id')->index(); // group 5 — plain uuid
            $table->string('message_wamid'); // the first inbound message carrying the referral
            $table->string('source_id')->nullable(); // ad id
            $table->string('source_type')->nullable(); // ad|post
            $table->text('source_url')->nullable();
            $table->text('headline')->nullable();
            $table->text('body')->nullable();
            $table->string('media_type')->nullable(); // image|video
            $table->text('image_url')->nullable();
            $table->text('video_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->string('ctwa_clid')->nullable(); // click id for Conversions API attribution
            $table->jsonb('welcome_message')->nullable();
            $table->timestampTz('occurred_at');
        });

        // What we report back to Meta (Conversions API).
        Schema::create('conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->uuid('order_id')->nullable()->index(); // group 9 — plain uuid
            $table->string('event_name'); // enum: Purchase|Lead|AddToCart|InitiateCheckout (app-level)
            $table->bigInteger('value_minor');
            $table->char('currency', 3);
            $table->string('ctwa_clid');
            $table->timestampTz('event_time');
            $table->string('dedup_key')->unique();
            $table->string('status'); // enum: pending|sent|failed (app-level)
            $table->jsonb('response')->nullable(); // null until Meta responds
            $table->timestampTz('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
        Schema::dropIfExists('ctwa_referrals');
    }
};
