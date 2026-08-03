<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Group 2 — Meta assets (docs/02-data-model.md §2).
 *
 * Tables: waba_accounts, phone_numbers, meta_credentials, onboarding_sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waba_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('waba_id')->unique(); // Meta WABA ID
            $table->string('owner_business_id'); // business portfolio ID
            $table->string('solution_id')->nullable(); // set when onboarded via a Multi-Partner Solution
            $table->string('name');
            $table->string('timezone_id'); // drives analytics day boundaries
            $table->char('currency', 3); // Meta's billing currency for this WABA
            $table->string('review_status'); // from account_review_update
            $table->string('account_status'); // from account_update
            $table->string('business_verification_status');
            $table->string('portfolio_messaging_limit')->nullable(); // 2000|10000|100000|UNLIMITED
            $table->boolean('is_subscribed')->default(false); // our app subscribed to this WABA's webhooks
            $table->boolean('payment_ready')->default(false); // client has attached a payment method
            $table->timestampTz('onboarded_at')->nullable();
            $table->timestampTz('offboarded_at')->nullable();
        });

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('waba_account_id')->constrained('waba_accounts');
            $table->index('waba_account_id');
            $table->string('phone_number_id')->unique(); // Meta business phone number ID
            $table->string('display_phone_number'); // E.164-ish as Meta returns it
            $table->string('verified_name');
            $table->string('code_verification_status');
            $table->string('quality_rating'); // enum: GREEN|YELLOW|RED|UNKNOWN (app-level)
            $table->string('messaging_limit_tier'); // legacy per-number field as Meta returns it
            $table->string('throughput_level'); // STANDARD, HIGH, ...
            $table->string('platform_type'); // CLOUD_API, ...
            $table->boolean('is_on_biz_app')->default(false); // true = Coexistence number
            $table->boolean('is_official_business_account')->default(false);
            $table->timestampTz('registered_at')->nullable();
            $table->boolean('pin_set')->default(false); // 2FA PIN configured
            $table->jsonb('profile'); // about, address, description, email, websites, vertical, ...
            $table->string('status'); // enum: pending|active|disconnected (app-level)
        });

        Schema::create('meta_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('waba_account_id')->nullable()->constrained('waba_accounts');
            $table->string('type'); // enum: business|bisu|system (app-level)
            $table->text('token'); // `encrypted` cast — Laravel app-key encryption
            $table->string('token_last4', 8); // for display only
            $table->jsonb('scopes'); // granted permissions
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->integer('failure_count')->default(0); // breaker after N consecutive auth failures
        });

        // Doc §2: unique (tenant_id, waba_account_id, type) where revoked_at is null.
        DB::statement(
            'CREATE UNIQUE INDEX meta_credentials_active_unique
             ON meta_credentials (tenant_id, waba_account_id, type)
             WHERE revoked_at IS NULL'
        );

        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index(); // null until the tenant is created
            $table->string('nonce')->unique(); // ties the browser session to the exchange
            $table->string('feature_type')->nullable(); // whatsapp_business_app_onboarding for Coexistence
            $table->string('es_version'); // e.g. v4
            $table->jsonb('events')->default('[]'); // ordered list of ES session events from the JS SDK
            $table->string('waba_id')->nullable(); // captured on FINISH
            $table->string('phone_number_id')->nullable(); // captured on FINISH
            $table->timestampTz('code_exchanged_at')->nullable();
            $table->timestampTz('history_sync_requested_at')->nullable(); // 24h clock starts at onboarding
            $table->timestampTz('history_sync_completed_at')->nullable();
            $table->timestampTz('contacts_sync_requested_at')->nullable();
            $table->string('status'); // enum: started|finished|exchanged|registered|syncing|complete|failed
            $table->jsonb('failure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sessions');
        Schema::dropIfExists('meta_credentials');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('waba_accounts');
    }
};
