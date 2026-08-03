<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PhoneNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property string $phone_number_id
 * @property string $display_phone_number
 * @property string $verified_name
 * @property string $code_verification_status
 * @property string $quality_rating
 * @property string $messaging_limit_tier
 * @property string $throughput_level
 * @property string $platform_type
 * @property bool $is_on_biz_app
 * @property bool $is_official_business_account
 * @property Carbon|null $registered_at
 * @property bool $pin_set
 * @property array<string, mixed> $profile
 * @property string $status
 * @property-read Tenant $tenant
 * @property-read WabaAccount $wabaAccount
 * @property-read Collection<int, Conversation> $conversations
 * @property-read Collection<int, Campaign> $campaigns
 * @property-read MbaAgent|null $mbaAgent
 * @property-read Collection<int, HealthEvent> $healthEvents
 */
#[Fillable(['waba_account_id', 'phone_number_id', 'display_phone_number', 'verified_name', 'code_verification_status', 'quality_rating', 'messaging_limit_tier', 'throughput_level', 'platform_type', 'is_on_biz_app', 'is_official_business_account', 'registered_at', 'pin_set', 'profile', 'status'])]
class PhoneNumber extends Model
{
    use BelongsToTenant, HasUuids;

    /** @use HasFactory<PhoneNumberFactory> */
    use HasFactory;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the WABA this number belongs to.
     *
     * @return BelongsTo<WabaAccount, $this>
     */
    public function wabaAccount(): BelongsTo
    {
        return $this->belongsTo(WabaAccount::class);
    }

    /**
     * Get the conversations held on this number.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the campaigns sent from this number.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Get the Meta Business Agent attached to this number (one per number).
     *
     * @return HasOne<MbaAgent, $this>
     */
    public function mbaAgent(): HasOne
    {
        return $this->hasOne(MbaAgent::class);
    }

    /**
     * Get the health events recorded against this number.
     *
     * @return HasMany<HealthEvent, $this>
     */
    public function healthEvents(): HasMany
    {
        return $this->hasMany(HealthEvent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_on_biz_app' => 'boolean',
            'is_official_business_account' => 'boolean',
            'registered_at' => 'datetime',
            'pin_set' => 'boolean',
            'profile' => 'array',
        ];
    }
}
