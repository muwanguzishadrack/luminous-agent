<?php

namespace App\Models;

use App\Enums\HealthSeverity;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fed by quality/account/capability webhooks and our own rate-limit watchdog
 * (docs/02-data-model.md §11).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string|null $phone_number_id
 * @property string $kind
 * @property HealthSeverity $severity
 * @property array<string, mixed> $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property-read Tenant $tenant
 * @property-read PhoneNumber|null $phoneNumber
 * @property-read User|null $acknowledgedBy
 */
#[Fillable(['phone_number_id', 'kind', 'severity', 'payload', 'occurred_at', 'acknowledged_at', 'acknowledged_by'])]
class HealthEvent extends Model
{
    use BelongsToTenant;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the phone number this event concerns, if any.
     *
     * @return BelongsTo<PhoneNumber, $this>
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Get the user who acknowledged the event, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => HealthSeverity::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
