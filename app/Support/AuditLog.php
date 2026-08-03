<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Support\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit writer (docs/02 §1, docs/modules/m0-onboarding.md §6).
 * Raw insert: audit_logs is bigint-pk append-only with created_at only.
 */
class AuditLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $action,
        ActorType $actorType = ActorType::System,
        ?string $actorId = null,
        ?Model $subject = null,
        array $context = [],
    ): void {
        DB::table('audit_logs')->insert([
            'tenant_id' => Tenancy::currentIdOrFail(),
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => json_encode($context),
            'created_at' => now(),
        ]);
    }
}
