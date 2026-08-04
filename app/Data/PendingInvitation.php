<?php

namespace App\Data;

use App\Models\TeamInvitation;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An unanswered invitation addressed to the signed-in user, as rendered on
 * the standalone invitations page and the dashboard modal.
 *
 * Carries the invitation `code` rather than its id: the code is what the
 * accept/decline routes bind on, and it is already in the invitee's inbox.
 */
#[TypeScript]
readonly class PendingInvitation
{
    public function __construct(
        public string $code,
        public string $inviterName,
        public string $teamName,
        public string $roleLabel,
    ) {
        //
    }

    public static function fromModel(TeamInvitation $invitation): self
    {
        return new self(
            code: $invitation->code,
            inviterName: $invitation->inviter->name,
            teamName: $invitation->team->name,
            roleLabel: $invitation->role->label(),
        );
    }
}
