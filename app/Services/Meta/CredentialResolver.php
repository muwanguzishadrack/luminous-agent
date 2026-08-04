<?php

namespace App\Services\Meta;

use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use App\Services\Meta\Exceptions\CredentialMissing;
use App\Services\Meta\Exceptions\CredentialRevoked;

/**
 * The token vault's read side (docs/modules/m0-onboarding.md §2): resolves
 * the current team's credential — MetaCredential is team-scoped, so the
 * caller must have established team context — and returns a GraphClient
 * carrying that bearer token, wrapped in the revocation circuit breaker.
 */
class CredentialResolver
{
    public function __construct(private readonly GraphClient $client) {}

    /**
     * A GraphClient authenticated with the team's business token — the
     * credential used for all Cloud API calls (docs/reference §1).
     */
    public function businessClient(): GraphClient
    {
        $credential = $this->businessCredential();

        return new CircuitBreakerGraphClient(
            $this->client->withToken($credential->token),
            $credential,
        );
    }

    /**
     * @throws CredentialRevoked when the team's business token has been revoked
     * @throws CredentialMissing when the team has never connected WhatsApp
     */
    public function businessCredential(): MetaCredential
    {
        $credential = MetaCredential::query()
            ->where('type', MetaCredentialType::Business)
            ->whereNull('revoked_at')
            ->orderByDesc('issued_at')
            ->first();

        if ($credential !== null) {
            return $credential;
        }

        $revoked = MetaCredential::query()
            ->where('type', MetaCredentialType::Business)
            ->orderByDesc('revoked_at')
            ->first();

        if ($revoked !== null) {
            throw new CredentialRevoked($revoked);
        }

        throw new CredentialMissing(MetaCredentialType::Business);
    }
}
