<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security for every table carrying tenant_id (docs/02-data-model.md,
 * docs/05-security-multitenancy.md). Defence in depth behind the global
 * Eloquent tenant scope: sessions must SET app.tenant_id before touching
 * tenant-scoped rows.
 *
 * Excluded (no tenant_id column): tenants, users, rate_cards,
 * contact_label, conversation_label, segment_members, mba_connector_tools.
 */
return new class extends Migration
{
    /**
     * Tables with a NOT NULL tenant_id — strict isolation.
     */
    private const TENANT_TABLES = [
        // Group 1 — tenancy & identity (created by the group-1 migration)
        // tenant_user gets a bespoke user-aware policy below: users must see
        // their own memberships across tenants (switcher, login) pre-context.
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
     * Tables where tenant_id is nullable — null rows are platform-level and
     * remain visible to every session.
     */
    private const NULLABLE_TENANT_TABLES = [
        'webhook_deliveries',
        'iotec_wallets',
        'onboarding_sessions',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            $this->enableRls($table, "tenant_id = current_setting('app.tenant_id', true)::uuid");
        }

        foreach (self::NULLABLE_TENANT_TABLES as $table) {
            $this->enableRls($table, "tenant_id IS NULL OR tenant_id = current_setting('app.tenant_id', true)::uuid");
        }

        // Deliberate exception: tenant_user carries tenant_id but gets NO RLS
        // policy. It is the context-bootstrapping bridge (tenant switcher,
        // registration, admin managing members of a non-current tenant, a
        // removed member's fallback-tenant lookup) — every one of those is a
        // legitimate cross-context read. It holds only ids, role and status;
        // isolation is enforced by authorization policies and user-scoped
        // relations (docs/05-security-multitenancy.md §1).
    }

    public function down(): void
    {
        foreach (array_merge(self::TENANT_TABLES, self::NULLABLE_TENANT_TABLES) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function enableRls(string $table, string $predicate): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$table}
             USING ({$predicate})
             WITH CHECK ({$predicate})"
        );
    }
};
