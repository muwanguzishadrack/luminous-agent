<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_id
 * @property string $owner_business_id
 * @property string|null $solution_id
 * @property string $name
 * @property string $timezone_id
 * @property string $currency
 * @property string $review_status
 * @property string $account_status
 * @property string $business_verification_status
 * @property string|null $portfolio_messaging_limit
 * @property bool $is_subscribed
 * @property bool $payment_ready
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $offboarded_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, PhoneNumber> $phoneNumbers
 * @property-read Collection<int, MetaCredential> $metaCredentials
 * @property-read Collection<int, Template> $templates
 * @property-read Collection<int, AnalyticsSnapshot> $analyticsSnapshots
 */
#[Fillable(['waba_id', 'owner_business_id', 'solution_id', 'name', 'timezone_id', 'currency', 'review_status', 'account_status', 'business_verification_status', 'portfolio_messaging_limit', 'is_subscribed', 'payment_ready', 'onboarded_at', 'offboarded_at'])]
class WabaAccount extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the phone numbers on this WABA.
     *
     * @return HasMany<PhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    /**
     * Get the credentials vaulted for this WABA.
     *
     * @return HasMany<MetaCredential, $this>
     */
    public function metaCredentials(): HasMany
    {
        return $this->hasMany(MetaCredential::class);
    }

    /**
     * Get the message templates registered under this WABA.
     *
     * @return HasMany<Template, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Get the cached analytics pulls for this WABA.
     *
     * @return HasMany<AnalyticsSnapshot, $this>
     */
    public function analyticsSnapshots(): HasMany
    {
        return $this->hasMany(AnalyticsSnapshot::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_subscribed' => 'boolean',
            'payment_ready' => 'boolean',
            'onboarded_at' => 'datetime',
            'offboarded_at' => 'datetime',
        ];
    }
}
