<?php

namespace App\Services\Meta\Exceptions;

use App\Enums\MetaCredentialType;
use RuntimeException;

/**
 * No active credential of the requested type is vaulted for the current
 * team. The UI renders this as a "connect WhatsApp" prompt, never a 500
 * (docs/modules/m0-onboarding.md §2).
 */
class CredentialMissing extends RuntimeException
{
    public function __construct(public readonly MetaCredentialType $type)
    {
        parent::__construct(
            sprintf('No active Meta %s credential is vaulted for the current team.', $type->value),
        );
    }
}
