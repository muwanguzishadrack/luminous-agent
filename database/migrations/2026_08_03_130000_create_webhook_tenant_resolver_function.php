<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Webhook tenant resolution is inherently cross-tenant: the delivery arrives
 * with no context and the owning tenant must be looked up by Meta asset id.
 * A SECURITY DEFINER function owned by the migration role (BYPASSRLS) exposes
 * EXACTLY one fact — asset id → tenant_id — to the runtime role without
 * weakening any table policy (docs/05 §1, "dangerous paths").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION resolve_webhook_tenant(p_phone_number_id text, p_waba_id text)
            RETURNS uuid
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT tenant_id FROM (
                    SELECT tenant_id, 1 AS priority
                    FROM phone_numbers
                    WHERE p_phone_number_id IS NOT NULL
                      AND phone_number_id = p_phone_number_id
                    UNION ALL
                    SELECT tenant_id, 2 AS priority
                    FROM waba_accounts
                    WHERE p_waba_id IS NOT NULL
                      AND waba_id = p_waba_id
                ) candidates
                ORDER BY priority
                LIMIT 1
            $$;
        SQL);

        DB::statement('GRANT EXECUTE ON FUNCTION resolve_webhook_tenant(text, text) TO PUBLIC');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS resolve_webhook_tenant(text, text)');
    }
};
