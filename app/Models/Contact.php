<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $wa_id
 * @property string $phone_e164
 * @property string|null $profile_name
 * @property string|null $display_name
 * @property string|null $locale
 * @property string $lifecycle_stage
 * @property string|null $owner_id
 * @property string $source
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 * @property int $lifetime_value
 * @property int $orders_count
 * @property bool $is_blocked
 * @property Carbon|null $undeliverable_at
 * @property array<string, mixed> $custom_fields
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read User|null $owner
 * @property-read Collection<int, ContactIdentifier> $identifiers
 * @property-read Collection<int, Consent> $consents
 * @property-read Collection<int, ConsentState> $consentStates
 * @property-read Collection<int, Conversation> $conversations
 * @property-read Collection<int, Note> $notes
 * @property-read Collection<int, Order> $orders
 * @property-read Collection<int, Label> $labels
 * @property-read Collection<int, Segment> $segments
 */
#[Fillable(['wa_id', 'phone_e164', 'profile_name', 'display_name', 'locale', 'lifecycle_stage', 'owner_id', 'source', 'first_seen_at', 'last_inbound_at', 'last_outbound_at', 'lifetime_value', 'orders_count', 'is_blocked', 'undeliverable_at', 'custom_fields'])]
class Contact extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the assigned CRM owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get the identifiers (wa_id, BSUIDs, phones) attached to this contact.
     *
     * @return HasMany<ContactIdentifier, $this>
     */
    public function identifiers(): HasMany
    {
        return $this->hasMany(ContactIdentifier::class);
    }

    /**
     * Get the append-only consent events for this contact.
     *
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /**
     * Get the materialised consent states — the rows the send guard reads.
     *
     * @return HasMany<ConsentState, $this>
     */
    public function consentStates(): HasMany
    {
        return $this->hasMany(ConsentState::class);
    }

    /**
     * Get the conversations with this contact.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the internal notes about this contact.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Get the orders placed by this contact.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the labels applied to this contact.
     *
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'contact_label');
    }

    /**
     * Get the static segments this contact is a member of.
     *
     * @return BelongsToMany<Segment, $this>
     */
    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class, 'segment_members')
            ->withPivot(['added_at']);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'is_blocked' => 'boolean',
            'undeliverable_at' => 'datetime',
            'custom_fields' => 'array',
        ];
    }
}
