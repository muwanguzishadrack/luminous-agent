<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    /** Edit the WhatsApp business profile and disconnect the number. */
    case ManageWhatsApp = 'whatsapp:manage';
}
