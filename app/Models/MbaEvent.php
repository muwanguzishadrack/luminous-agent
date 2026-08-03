<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Agent Events we push to Meta (docs/02-data-model.md §8).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property string|null $external_id
 * @property string $status
 * @property Carbon|null $sent_at
 * @property array<string, mixed>|null $error
 * @property-read Tenant $tenant
 * @property-read Conversation $conversation
 */
#[Fillable(['conversation_id', 'kind', 'payload', 'external_id', 'status', 'sent_at', 'error'])]
class MbaEvent extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the conversation this event was pushed for.
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
            'payload' => 'array',
            'sent_at' => 'datetime',
            'error' => 'array',
        ];
    }
}
