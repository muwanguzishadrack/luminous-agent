<?php

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One row per ioTec transaction attempt — status history lives in
 * payment_events (docs/02-data-model.md §9).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $order_id
 * @property string|null $contact_id
 * @property PaymentDirection $direction
 * @property string $provider
 * @property string $external_id
 * @property string|null $provider_id
 * @property string|null $vendor_transaction_id
 * @property string $category
 * @property string $wallet_id
 * @property string $currency
 * @property int $amount_minor
 * @property string|null $payer
 * @property string|null $payer_name
 * @property string|null $payee
 * @property string|null $payee_name
 * @property PaymentStatus $status
 * @property string|null $status_code
 * @property string|null $status_message
 * @property string|null $vendor
 * @property string|null $payment_channel
 * @property int|null $transaction_charge_minor
 * @property int|null $vendor_charge_minor
 * @property int|null $total_charge_minor
 * @property string|null $card_redirect_url
 * @property string|null $redirect_url
 * @property Carbon|null $requested_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $last_polled_at
 * @property array<string, mixed> $raw
 * @property string $idempotency_key
 * @property-read Tenant $tenant
 * @property-read Order|null $order
 * @property-read Contact|null $contact
 * @property-read IotecWallet $wallet
 * @property-read Collection<int, PaymentEvent> $events
 */
#[Fillable(['order_id', 'contact_id', 'direction', 'provider', 'external_id', 'provider_id', 'vendor_transaction_id', 'category', 'wallet_id', 'currency', 'amount_minor', 'payer', 'payer_name', 'payee', 'payee_name', 'status', 'status_code', 'status_message', 'vendor', 'payment_channel', 'transaction_charge_minor', 'vendor_charge_minor', 'total_charge_minor', 'card_redirect_url', 'redirect_url', 'requested_at', 'processed_at', 'last_polled_at', 'raw', 'idempotency_key'])]
class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the order this payment settles, if any.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the contact paying or being paid, if known.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Get the ioTec wallet this payment moves through.
     *
     * @return BelongsTo<IotecWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(IotecWallet::class, 'wallet_id');
    }

    /**
     * Get the append-only status events for this payment.
     *
     * @return HasMany<PaymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'status' => PaymentStatus::class,
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'raw' => 'array',
        ];
    }
}
