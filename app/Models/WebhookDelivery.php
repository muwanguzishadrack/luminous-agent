<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Raw, append-only webhook ingest — the audit trail and replay source for
 * everything inbound (docs/02-data-model.md §3).
 *
 * @property int $id
 * @property WebhookSource $source
 * @property string $body_sha256
 * @property array<string, mixed> $headers
 * @property array<string, mixed> $payload
 * @property string|null $team_id
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property WebhookDeliveryStatus $status
 * @property array<string, mixed>|null $error
 * @property-read Team|null $team
 */
#[Fillable(['source', 'body_sha256', 'headers', 'payload', 'received_at', 'processed_at', 'attempts', 'status', 'error'])]
class WebhookDelivery extends Model
{
    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => WebhookSource::class,
            'headers' => 'array',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'status' => WebhookDeliveryStatus::class,
            'error' => 'array',
        ];
    }

    /**
     * Nullable by design: rows are created platform-level before any team
     * context exists (docs/02 §3) — hence no BelongsToTeam trait.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
