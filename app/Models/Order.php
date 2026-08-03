<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string|null $conversation_id
 * @property string $reference
 * @property string $source
 * @property string|null $origin_wamid
 * @property array<int, array<string, mixed>> $items
 * @property int $subtotal_minor
 * @property int $shipping_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property string $currency
 * @property OrderStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property array<string, mixed> $meta
 * @property-read Tenant $tenant
 * @property-read Contact $contact
 * @property-read Conversation|null $conversation
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Conversion> $conversions
 */
#[Fillable(['contact_id', 'conversation_id', 'reference', 'source', 'origin_wamid', 'items', 'subtotal_minor', 'shipping_minor', 'discount_minor', 'total_minor', 'currency', 'status', 'paid_at', 'cancelled_at', 'notes', 'meta'])]
class Order extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the contact who placed the order.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the conversation the order originated in, if any.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the payment attempts against this order.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the conversions reported to Meta for this order.
     *
     * @return HasMany<Conversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(Conversion::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'status' => OrderStatus::class,
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
