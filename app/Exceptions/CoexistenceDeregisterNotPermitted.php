<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Meta does not permit `POST /{phone-number-id}/deregister` for a number that
 * is still on the WhatsApp Business app (`is_on_biz_app: true`). The client
 * disconnects from the handset instead, which fires `account_update` /
 * `PARTNER_REMOVED` (docs/reference/whatsapp-cloud-api.md §5).
 */
class CoexistenceDeregisterNotPermitted extends RuntimeException
{
    public function __construct(public readonly string $displayPhoneNumber)
    {
        parent::__construct(
            "{$displayPhoneNumber} is a Coexistence number and cannot be deregistered through the API. "
            .'Disconnect it from the WhatsApp Business app instead: Settings → Account → Business Platform → Disconnect.',
        );
    }
}
