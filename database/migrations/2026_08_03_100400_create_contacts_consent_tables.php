<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group 4 — Contacts & consent (docs/02-data-model.md §4).
 *
 * Tables: contacts, contact_identifiers, consents, consent_states, labels,
 * contact_label, conversation_label, notes, segments, segment_members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('wa_id'); // WhatsApp user id (MSISDN, no +)
            $table->string('phone_e164'); // normalised, for display and ioTec
            $table->string('profile_name')->nullable(); // as WhatsApp reports it — not editable by us
            $table->string('display_name')->nullable(); // CRM-editable
            $table->string('locale')->nullable(); // drives template language selection
            $table->string('lifecycle_stage'); // lead|engaged|customer|churned — tenant-configurable
            $table->uuid('owner_id')->nullable(); // assigned CRM owner (user)
            $table->string('source'); // inbound|ctwa|import|coexistence|api|qr
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();
            $table->bigInteger('lifetime_value')->default(0); // minor units, denormalised from orders
            $table->integer('orders_count')->default(0); // denormalised
            $table->boolean('is_blocked')->default(false); // platform-level block applied
            $table->timestampTz('undeliverable_at')->nullable(); // set on send error 131026
            $table->jsonb('custom_fields')->default('{}'); // tenant-defined schema
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'wa_id']);
        });

        // Doc §4: index (tenant_id, last_inbound_at desc) and GIN on custom_fields.
        DB::statement('CREATE INDEX contacts_tenant_last_inbound_idx ON contacts (tenant_id, last_inbound_at DESC)');
        DB::statement('CREATE INDEX contacts_custom_fields_gin ON contacts USING GIN (custom_fields)');

        Schema::create('contact_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('contact_id')->constrained('contacts');
            $table->index('contact_id');
            $table->string('kind'); // enum: wa_id|bsuid|parent_bsuid|phone (app-level)
            $table->string('value');
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('retired_at')->nullable(); // retired when the number changes hands

            $table->unique(['tenant_id', 'kind', 'value']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id(); // append-only, never updated: bigint pk
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('contact_id')->constrained('contacts');
            $table->index('contact_id');
            $table->string('scope'); // enum: marketing|utility|authentication|all
            $table->string('state'); // enum: granted|revoked
            $table->string('source'); // whatsapp_native|inbound_keyword|web_form|import|agent|api|system
            $table->jsonb('evidence'); // wamid, form payload, IP, uploader, screenshot ref
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
        });

        // Materialised read model, one row per contact+scope — the table the send guard reads.
        Schema::create('consent_states', function (Blueprint $table) {
            $table->uuid('id')->primary(); // doc lists no pk; uuid pk per project convention
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('contact_id')->constrained('contacts');
            $table->string('scope');
            $table->string('state');
            $table->string('source');
            $table->timestampTz('occurred_at');
            $table->foreignId('consent_id')->constrained('consents');

            $table->unique(['contact_id', 'scope']);
        });

        Schema::create('labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('color');
            $table->string('kind'); // enum: contact|conversation (app-level)
            $table->uuid('created_by')->nullable();
        });

        // Pivots are simple (doc §4) — no tenant_id column, scoping flows through labels.
        Schema::create('contact_label', function (Blueprint $table) {
            $table->foreignUuid('label_id')->constrained('labels')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();

            $table->primary(['label_id', 'contact_id']);
            $table->index('contact_id');
        });

        Schema::create('conversation_label', function (Blueprint $table) {
            $table->foreignUuid('label_id')->constrained('labels')->cascadeOnDelete();
            $table->uuid('conversation_id'); // conversations table is group 5 — plain uuid

            $table->primary(['label_id', 'conversation_id']);
            $table->index('conversation_id');
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('contact_id')->constrained('contacts');
            $table->index('contact_id');
            $table->uuid('conversation_id')->nullable()->index(); // group 5 — plain uuid
            $table->uuid('user_id');
            $table->text('body');
            $table->jsonb('mentions');
            $table->timestampTz('created_at');
        });

        Schema::create('segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->jsonb('definition'); // AST of the filter tree (field/op/value + and/or)
            $table->boolean('is_dynamic')->default(true); // dynamic = re-evaluated at send
            $table->integer('estimated_size')->nullable();
            $table->timestampTz('last_evaluated_at')->nullable();
            $table->softDeletesTz();
        });

        // Only populated for static segments and campaign snapshots (doc §4).
        Schema::create('segment_members', function (Blueprint $table) {
            $table->foreignUuid('segment_id')->constrained('segments')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->timestampTz('added_at');

            $table->primary(['segment_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_members');
        Schema::dropIfExists('segments');
        Schema::dropIfExists('notes');
        Schema::dropIfExists('conversation_label');
        Schema::dropIfExists('contact_label');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('consent_states');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('contact_identifiers');
        Schema::dropIfExists('contacts');
    }
};
