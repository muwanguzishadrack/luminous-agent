<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security for every table carrying team_id (docs/02-data-model.md,
 * docs/05-security-multitenancy.md). Defence in depth behind the global
 * Eloquent team scope: sessions must SET app.team_id before touching
 * team-scoped rows.
 *
 * Excluded (no team_id column): teams, users, rate_cards,
 * contact_label, conversation_label, segment_members, mba_connector_tools.
 */
return new class extends Migration
{
    /**
     * Tables with a NOT NULL team_id — strict isolation.
     */
    private const TEAM_TABLES = [
        // Group 1 — teams & identity (created by the group-1 migration)
        // team_user gets a bespoke user-aware policy below: a user must be
        // able to read their own membership row *before* team context exists,
        // because that row is what establishes the context (docs/05 §1).
        'audit_logs',
        'admin_sessions',
        // Group 2 — Meta assets
        'waba_accounts',
        'phone_numbers',
        'meta_credentials',
        // Group 4 — contacts & consent
        'contacts',
        'contact_identifiers',
        'consents',
        'consent_states',
        'labels',
        'notes',
        'segments',
        // Group 5 — conversations & messages
        'conversations',
        'media',
        'messages',
        'message_events',
        'thread_control_events',
        'canned_replies',
        // Group 6 — templates
        'template_group',
        'templates',
        'template_events',
        // Group 7 — campaigns
        'campaigns',
        'campaign_recipients',
        'campaign_clicks',
        'sequences',
        'sequence_steps',
        'sequence_enrollments',
        // Group 8 — Meta Business Agent
        'mba_agents',
        'mba_allowlist_entries',
        'mba_knowledge_sources',
        'connector_tokens',
        'mba_connectors',
        'mba_events',
        'mba_evals',
        // Group 9 — commerce & payments
        'catalogs',
        'products',
        'orders',
        'payments',
        'payment_events',
        // Group 10 — CTWA
        'ctwa_referrals',
        'conversions',
        // Group 11 — analytics & billing
        'usage_meters',
        'wallet_entries',
        'analytics_snapshots',
        'health_events',
    ];

    /**
     * Tables where team_id is nullable — null rows are platform-level and
     * remain visible to every session.
     */
    private const NULLABLE_TEAM_TABLES = [
        'webhook_deliveries',
        'iotec_wallets',
        'onboarding_sessions',
    ];

    public function up(): void
    {
        foreach (self::TEAM_TABLES as $table) {
            $this->enableRls($table, "team_id = NULLIF(current_setting('app.team_id', true), '')::uuid");
        }

        foreach (self::NULLABLE_TEAM_TABLES as $table) {
            $this->enableRls($table, "team_id IS NULL OR team_id = NULLIF(current_setting('app.team_id', true), '')::uuid");
        }

        // team_user is the one table the plain policy cannot cover: at
        // authentication we must read a user's membership before team context
        // exists. Its policy is user-aware, so a signed-in user with no team
        // context can read exactly one row — their own membership — and that
        // row is what establishes the context (docs/05 §1 layer 2).
        $this->enableRls('team_user', <<<'SQL'
            team_id = NULLIF(current_setting('app.team_id', true), '')::uuid
            OR user_id = NULLIF(current_setting('app.user_id', true), '')::uuid
            SQL);
    }

    public function down(): void
    {
        foreach ([...self::TEAM_TABLES, ...self::NULLABLE_TEAM_TABLES, 'team_user'] as $table) {
            DB::statement("DROP POLICY IF EXISTS team_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function enableRls(string $table, string $predicate): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY team_isolation ON {$table}
             USING ({$predicate})
             WITH CHECK ({$predicate})"
        );
    }
};
