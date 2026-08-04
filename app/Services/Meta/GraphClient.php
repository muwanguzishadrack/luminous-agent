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
     * Use the given bearer token (a per-team business token, or the BISU
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

    /**
     * GET /{phone-number-id}/whatsapp_business_profile — the readable half of
     * the profile, including `profile_picture_url`
     * (docs/reference/whatsapp-cloud-api.md §5).
     *
     * @return array<string, mixed>
     */
    public function businessProfile(string $phoneNumberId): array;

    /**
     * POST /{phone-number-id}/whatsapp_business_profile. `messaging_product`
     * is added here so no caller can forget it — Meta requires it on every
     * write. The response is `{"success": true}` and echoes nothing back, so
     * callers must re-read the profile afterwards.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateBusinessProfile(string $phoneNumberId, array $fields): array;

    /*
     * No deregister here on purpose. Disconnecting a team clears the
     * connection from Luminous and leaves the number registered
     * (docs/modules/m0-onboarding.md §7), so nothing may call
     * POST /{phone-number-id}/deregister — the one Cloud API call that takes
     * a client's number offline for every provider, capped at 10 attempts per
     * 72 hours, and refused outright for a Coexistence number.
     */

    /**
     * Resumable Upload API — uploads the bytes and returns the opaque file
     * handle that `profile_picture_handle` takes. Two legs: create the upload
     * session on the app node, then POST the binary to the session.
     */
    public function uploadResumable(string $contents, string $mimeType, string $fileName): string;
}
