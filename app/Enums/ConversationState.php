<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Conversation ownership state (docs/02-data-model.md §5).
 */
#[TypeScript]
enum ConversationState: string
{
    case Ai = 'ai';
    case Queued = 'queued';
    case Human = 'human';
    case Closed = 'closed';
}
