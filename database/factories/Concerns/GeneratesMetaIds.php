<?php

namespace Database\Factories\Concerns;

/**
 * Realistic Meta / WhatsApp shaped identifiers for factories and seeders:
 * numeric-string asset ids, `wamid.`-prefixed base64ish message ids and
 * Ugandan MSISDN wa_ids (2567XXXXXXXX, no `+`).
 */
trait GeneratesMetaIds
{
    /**
     * A Meta asset id (WABA id, phone_number_id, business id, ad id) — a
     * 15-digit numeric string as the Graph API returns them.
     */
    protected function metaId(): string
    {
        return (string) fake()->numberBetween(100_000_000_000_000, 999_999_999_999_999);
    }

    /**
     * A WhatsApp message id: `wamid.` followed by a base64 blob.
     */
    protected function wamid(): string
    {
        return 'wamid.'.base64_encode(random_bytes(24));
    }

    /**
     * A Ugandan WhatsApp user id — MSISDN with no `+` (2567XXXXXXXX).
     */
    protected function waId(): string
    {
        return '2567'.fake()->unique()->numerify('########');
    }

    /**
     * A CTWA click id as Meta mints them for Conversions API attribution.
     */
    protected function ctwaClid(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
