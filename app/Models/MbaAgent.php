<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Meta Business Agent — one per phone number (docs/02-data-model.md §8).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phone_number_id
 * @property array<string, mixed> $eligibility
 * @property string|null $vertical
 * @property Carbon|null $tos_client_accepted_at
 * @property Carbon|null $onboarded_at
 * @property bool $enabled
 * @property Carbon|null $enabled_at
 * @property Carbon|null $disabled_at
 * @property array<string, mixed> $settings
 * @property array<string, mixed> $skills
 * @property string $allowlist_mode
 * @property Carbon|null $last_synced_at
 * @property-read Tenant $tenant
 * @property-read PhoneNumber $phoneNumber
 * @property-read Collection<int, MbaAllowlistEntry> $allowlistEntries
 * @property-read Collection<int, MbaKnowledgeSource> $knowledgeSources
 * @property-read Collection<int, MbaConnector> $connectors
 * @property-read Collection<int, MbaEval> $evals
 */
#[Fillable(['phone_number_id', 'eligibility', 'vertical', 'tos_client_accepted_at', 'onboarded_at', 'enabled', 'enabled_at', 'disabled_at', 'settings', 'skills', 'allowlist_mode', 'last_synced_at'])]
class MbaAgent extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the phone number this agent is attached to.
     *
     * @return BelongsTo<PhoneNumber, $this>
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /**
     * Get the allowlist entries for this agent.
     *
     * @return HasMany<MbaAllowlistEntry, $this>
     */
    public function allowlistEntries(): HasMany
    {
        return $this->hasMany(MbaAllowlistEntry::class);
    }

    /**
     * Get the knowledge sources feeding this agent.
     *
     * @return HasMany<MbaKnowledgeSource, $this>
     */
    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(MbaKnowledgeSource::class);
    }

    /**
     * Get the connectors exposed to this agent.
     *
     * @return HasMany<MbaConnector, $this>
     */
    public function connectors(): HasMany
    {
        return $this->hasMany(MbaConnector::class);
    }

    /**
     * Get the test/eval runs against this agent.
     *
     * @return HasMany<MbaEval, $this>
     */
    public function evals(): HasMany
    {
        return $this->hasMany(MbaEval::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'eligibility' => 'array',
            'tos_client_accepted_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'settings' => 'array',
            'skills' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
