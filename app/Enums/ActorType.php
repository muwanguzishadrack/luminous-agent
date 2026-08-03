<?php

namespace App\Enums;

/**
 * Who performed an action (docs/02-data-model.md §1).
 */
enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Mba = 'mba';
    case OwnerDevice = 'owner_device';
}
