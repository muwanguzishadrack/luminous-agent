<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a consent event came from (docs/02-data-model.md §4).
 */
#[TypeScript]
enum ConsentSource: string
{
    case WhatsappNative = 'whatsapp_native';
    case InboundKeyword = 'inbound_keyword';
    case WebForm = 'web_form';
    case Import = 'import';
    case Agent = 'agent';
    case Api = 'api';
    case System = 'system';
}
