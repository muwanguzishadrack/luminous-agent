<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group 9 — Commerce & payments (docs/02-data-model.md §9).
 *
 * Tables: catalogs, products, orders, iotec_wallets, payments, payment_events.
 * (iotec_wallets created before payments so payments.wallet_id can be a real FK.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Doc §9 only details `products`; catalogs expanded minimally as the Meta catalog container.
        Schema::create('catalogs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->string('meta_catalog_id')->nullable();
            $table->string('name');
            $table->string('sync_status')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->foreignUuid('catalog_id')->constrained('catalogs');
            $table->index('catalog_id');
            $table->string('retailer_id'); // our SKU — the id used in WhatsApp product messages
            $table->string('meta_product_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3); // UGX
            $table->string('availability'); // enum: in_stock|out_of_stock|preorder (app-level)
            $table->text('image_url')->nullable();
            $table->text('url')->nullable();
            $table->jsonb('attributes');
            $table->string('sync_status')->nullable();
            $table->timestampTz('last_synced_at')->nullable();

            $table->unique(['team_id', 'retailer_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('contact_id')->index(); // group 4 — plain uuid
            $table->uuid('conversation_id')->nullable()->index(); // group 5 — plain uuid; api/mba orders may precede a conversation
            $table->string('reference')->unique(); // human-facing order number
            $table->string('source'); // enum: whatsapp_cart|agent|mba|api (app-level)
            $table->string('origin_wamid')->nullable(); // the `order` message that created it
            $table->jsonb('items'); // [{retailer_id, name, qty, unit_price_minor, currency}]
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('shipping_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('status'); // draft|pending_payment|partially_paid|paid|fulfilling|shipped|completed|cancelled|refunded
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('meta');
        });

        Schema::create('iotec_wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->nullable()->index(); // null for our own platform wallet
            $table->uuid('iotec_wallet_id')->unique(); // ioTec's wallet id — one local row per remote wallet
            $table->string('name');
            $table->char('currency', 3);
            $table->bigInteger('actual_balance_minor');
            $table->bigInteger('available_balance_minor');
            $table->text('collection_callback_url')->nullable();
            $table->text('disbursement_callback_url')->nullable();
            $table->string('callback_header_name')->nullable(); // null falls back to config/iotec.php
            $table->text('callback_header_value')->nullable(); // `encrypted` cast
            $table->timestampTz('last_synced_at')->nullable();
        });

        // One row per ioTec transaction attempt. Status history lives in payment_events.
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->foreignUuid('order_id')->nullable()->constrained('orders'); // nullable = standalone collection
            $table->index('order_id');
            $table->uuid('contact_id')->nullable();
            $table->string('direction'); // enum: collection|disbursement (app-level)
            $table->string('provider'); // iotec
            $table->string('external_id'); // our ULID sent to ioTec as externalId — WE guarantee uniqueness
            $table->uuid('provider_id')->nullable()->unique(); // ioTec's transaction id — authoritative reference
            $table->string('vendor_transaction_id')->nullable(); // MTN/Airtel/PegPay reference
            $table->string('category'); // enum: MobileMoney|Card|BankTransfer|WalletToWallet (app-level)
            $table->foreignUuid('wallet_id')->constrained('iotec_wallets'); // ioTec wallet used
            $table->char('currency', 3); // ITX (sandbox), UGX, USD
            $table->bigInteger('amount_minor'); // >= 500 major units per ioTec rule — validate before send
            $table->string('payer')->nullable(); // MSISDN for MoMo, email for Card
            $table->string('payer_name')->nullable();
            $table->string('payee')->nullable(); // disbursements
            $table->string('payee_name')->nullable();
            $table->string('status'); // Pending|SentToVendor|Success|Failed|AwaitingApproval|RolledBack|Scheduled|Cancelled|Rejected
            $table->string('status_code')->nullable(); // from ioTec
            $table->string('status_message')->nullable(); // from ioTec
            $table->string('vendor')->nullable(); // Mtn|Airtel|Visa|MasterCard|Stanbic|Mock|...
            $table->string('payment_channel')->nullable(); // Api|Portal|Link|Bulk|Woocommerce
            $table->bigInteger('transaction_charge_minor')->nullable();
            $table->bigInteger('vendor_charge_minor')->nullable();
            $table->bigInteger('total_charge_minor')->nullable();
            $table->text('card_redirect_url')->nullable(); // PegPay hosted form URL
            $table->text('redirect_url')->nullable(); // where we send the payer after completion
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->jsonb('raw'); // last full ioTec view model
            $table->string('idempotency_key')->unique(); // guards double-submission from our own UI

            $table->unique(['team_id', 'external_id']); // external_id unique per team
            $table->index(['team_id', 'status']);
            $table->index(['status', 'last_polled_at']); // for the poller
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id(); // append-only: bigint pk
            $table->uuid('team_id')->index();
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->index('payment_id');
            $table->string('status');
            $table->string('status_code')->nullable();
            $table->string('status_message')->nullable();
            $table->string('source'); // enum: callback|poll|manual (app-level)
            $table->jsonb('raw');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('iotec_wallets');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('catalogs');
    }
};
