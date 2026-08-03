<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property bool $is_dynamic
 * @property int|null $estimated_size
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, Contact> $contacts
 * @property-read Collection<int, Campaign> $campaigns
 */
#[Fillable(['name', 'definition', 'is_dynamic', 'estimated_size', 'last_evaluated_at'])]
class Segment extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the members — only populated for static segments and campaign snapshots.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'segment_members')
            ->withPivot(['added_at']);
    }

    /**
     * Get the campaigns targeting this segment.
     *
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'is_dynamic' => 'boolean',
            'last_evaluated_at' => 'datetime',
        ];
    }
}
