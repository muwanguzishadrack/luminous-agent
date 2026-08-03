<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only status ladder — lets a `read` arriving before `delivered` be
 * recorded honestly rather than clobbering state (docs/02-data-model.md §5).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $message_id
 * @property string $wamid
 * @property MessageStatus $status
 * @property int|null $error_code
 * @property array<string, mixed>|null $pricing
 * @property Carbon $occurred_at
 * @property array<string, mixed> $payload
 * @property-read Tenant $tenant
 * @property-read Message $message
 */
#[Fillable(['message_id', 'wamid', 'status', 'error_code', 'pricing', 'occurred_at', 'payload'])]
class MessageEvent extends Model
{
    use BelongsToTenant;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the message this event belongs to.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'pricing' => 'array',
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
