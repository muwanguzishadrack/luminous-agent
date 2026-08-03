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
 * Bearer tokens Meta uses to call us — only a hash is stored
 * (docs/02-data-model.md §8).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property array<int, string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, MbaConnector> $connectors
 */
#[Fillable(['name', 'token_hash', 'prefix', 'abilities', 'last_used_at', 'expires_at', 'revoked_at'])]
class ConnectorToken extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the connectors authenticating with this token.
     *
     * @return HasMany<MbaConnector, $this>
     */
    public function connectors(): HasMany
    {
        return $this->hasMany(MbaConnector::class, 'token_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
