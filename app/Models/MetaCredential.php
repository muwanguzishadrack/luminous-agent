<?php

namespace App\Models;

use App\Enums\MetaCredentialType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $waba_account_id
 * @property MetaCredentialType $type
 * @property string $token
 * @property string $token_last4
 * @property array<int, string> $scopes
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property int $failure_count
 * @property-read Tenant $tenant
 * @property-read WabaAccount|null $wabaAccount
 */
#[Fillable(['waba_account_id', 'type', 'token', 'token_last4', 'scopes', 'issued_at', 'expires_at', 'revoked_at', 'last_used_at', 'failure_count'])]
class MetaCredential extends Model
{
    use BelongsToTenant, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the WABA this credential is scoped to, if any.
     *
     * @return BelongsTo<WabaAccount, $this>
     */
    public function wabaAccount(): BelongsTo
    {
        return $this->belongsTo(WabaAccount::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MetaCredentialType::class,
            'token' => 'encrypted',
            'scopes' => 'array',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
