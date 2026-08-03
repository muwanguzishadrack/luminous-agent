<?php

namespace App\Services\Meta\Exceptions;

use App\Models\MetaCredential;
use RuntimeException;
use Throwable;

/**
 * The tenant's vaulted credential has been revoked — by Meta (consecutive
 * auth failures tripping the circuit breaker) or by the client in Business
 * Suite. The UI renders this as a reconnect prompt, never a 500
 * (docs/modules/m0-onboarding.md §2).
 */
class CredentialRevoked extends RuntimeException
{
    public function __construct(
        public readonly MetaCredential $credential,
        ?Throwable $previous = null,
    ) {
        // Identify the credential by its display suffix only — the token
        // value never appears in any message or log.
        parent::__construct(
            sprintf(
                'The Meta %s credential (…%s) has been revoked — the tenant must reconnect WhatsApp.',
                $credential->type->value,
                $credential->token_last4,
            ),
            0,
            $previous,
        );
    }
}
