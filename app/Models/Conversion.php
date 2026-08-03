<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What we report back to Meta via the Conversions API
 * (docs/02-data-model.md §10).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string|null $order_id
 * @property string $event_name
 * @property int $value_minor
 * @property string $currency
 * @property string $ctwa_clid
 * @property Carbon $event_time
 * @property string $dedup_key
 * @property string $status
 * @property array<string, mixed>|null $response
 * @property Carbon|null $sent_at
 * @property-read Tenant $tenant
 * @property-read Contact $contact
 * @property-read Order|null $order
 */
#[Fillable(['contact_id', 'order_id', 'event_name', 'value_minor', 'currency', 'ctwa_clid', 'event_time', 'dedup_key', 'status', 'response', 'sent_at'])]
class Conversion extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the contact this conversion is attributed to.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the order that produced this conversion, if any.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'response' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
