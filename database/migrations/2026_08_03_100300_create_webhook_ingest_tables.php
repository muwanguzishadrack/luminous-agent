<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 3 — Webhook ingest (docs/02-data-model.md §3).
 *
 * Tables: webhook_deliveries (raw, append-only; bigint pk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id(); // high-volume append-only: bigint pk
            $table->string('source'); // enum: meta|iotec (app-level)
            $table->char('body_sha256', 64);
            $table->jsonb('headers'); // signature, delivery id
            $table->jsonb('payload'); // raw body
            $table->uuid('team_id')->nullable()->index(); // resolved during processing, null if unresolvable
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->string('status'); // enum: pending|processed|partial|failed|ignored
            $table->jsonb('error')->nullable();

            $table->unique(['source', 'body_sha256']); // idempotent delivery
            $table->index(['status', 'received_at']);
            $table->index(['team_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
