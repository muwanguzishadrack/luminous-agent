<?php

namespace App\Services\Meta;

/**
 * Thin typed contract for the WhatsApp Cloud API / Graph API
 * (docs/reference/whatsapp-cloud-api.md). No business logic here —
 * guards, metering and persistence live in Actions.
 */
interface GraphClient
{
    /**
     * Use the given bearer token (a per-tenant business token, or the BISU
     * token for MBA calls) for subsequent requests.
     */
    public function withToken(string $token): static;

    /**
     * POST /{phone-number-id}/messages
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(string $phoneNumberId, array $payload): array;

    /**
     * GET /{node-id}?fields=…
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = []): array;

    /**
     * POST /{node-id}/{edge}
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array;

    /**
     * DELETE /{node-id}/{edge}
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function delete(string $path, array $params = []): array;

    /**
     * POST /oauth/access_token — Embedded Signup code exchange
     * (docs/modules/m0-onboarding.md §1). Never log the code or the token.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array;
}
