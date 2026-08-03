<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only payment status record — a callback and a poll reporting the
 * same status are both recorded (docs/02-data-model.md §9).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property PaymentStatus $status
 * @property string|null $status_code
 * @property string|null $status_message
 * @property string $source
 * @property array<string, mixed> $raw
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property-read Tenant $tenant
 * @property-read Payment $payment
 */
#[Fillable(['payment_id', 'status', 'status_code', 'status_message', 'source', 'raw', 'occurred_at', 'received_at'])]
class PaymentEvent extends Model
{
    use BelongsToTenant;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the payment this event belongs to.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'raw' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
