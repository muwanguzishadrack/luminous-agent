<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two inherently cross-team lookups, each exposed as a SECURITY DEFINER
 * function owned by the migration role (BYPASSRLS). Each returns EXACTLY one
 * fact to the runtime role, without weakening any table policy
 * (docs/05 §1, "dangerous paths"):
 *
 * - `resolve_webhook_team` — a delivery arrives with no context and the owning
 *   team must be looked up by Meta asset id.
 * - `email_belongs_to_a_team` — an invitation must be refused for someone who
 *   already belongs to a team (D-020), and their team is not ours to read.
 *   The answer is a single boolean: no id, no name, no membership row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION resolve_webhook_team(p_phone_number_id text, p_waba_id text)
            RETURNS uuid
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT team_id FROM (
                    SELECT team_id, 1 AS priority
                    FROM phone_numbers
                    WHERE p_phone_number_id IS NOT NULL
                      AND phone_number_id = p_phone_number_id
                    UNION ALL
                    SELECT team_id, 2 AS priority
                    FROM waba_accounts
                    WHERE p_waba_id IS NOT NULL
                      AND waba_id = p_waba_id
                ) candidates
                ORDER BY priority
                LIMIT 1
            $$;
        SQL);

        DB::statement('GRANT EXECUTE ON FUNCTION resolve_webhook_team(text, text) TO PUBLIC');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION email_belongs_to_a_team(p_email text)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM team_user tu
                    JOIN users u ON u.id = tu.user_id
                    WHERE LOWER(u.email) = LOWER(p_email)
                )
            $$;
        SQL);

        DB::statement('GRANT EXECUTE ON FUNCTION email_belongs_to_a_team(text) TO PUBLIC');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS email_belongs_to_a_team(text)');
        DB::statement('DROP FUNCTION IF EXISTS resolve_webhook_team(text, text)');
    }
};
