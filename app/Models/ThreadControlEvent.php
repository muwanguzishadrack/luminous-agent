<?php

namespace App\Models;

use App\Enums\ActorType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only handover-protocol audit (docs/02-data-model.md §5).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $event
 * @property string|null $previous_owner_app_id
 * @property string|null $new_owner_app_id
 * @property array<string, mixed> $metadata
 * @property ActorType $actor_type
 * @property string|null $actor_id
 * @property Carbon $occurred_at
 * @property-read Tenant $tenant
 * @property-read Conversation $conversation
 */
#[Fillable(['conversation_id', 'event', 'previous_owner_app_id', 'new_owner_app_id', 'metadata', 'actor_type', 'actor_id', 'occurred_at'])]
class ThreadControlEvent extends Model
{
    use BelongsToTenant;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the conversation whose control changed.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'actor_type' => ActorType::class,
            'occurred_at' => 'datetime',
        ];
    }
}
