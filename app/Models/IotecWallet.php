<?php

namespace App\Models;

use Database\Factories\IotecWalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Local mirror of an ioTec wallet; team_id is null for the platform wallet
 * (docs/02-data-model.md §9).
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $iotec_wallet_id
 * @property string $name
 * @property string $currency
 * @property int $actual_balance_minor
 * @property int $available_balance_minor
 * @property string|null $collection_callback_url
 * @property string|null $disbursement_callback_url
 * @property string|null $callback_header_name
 * @property string|null $callback_header_value
 * @property Carbon|null $last_synced_at
 * @property-read Team|null $team
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable(['iotec_wallet_id', 'name', 'currency', 'actual_balance_minor', 'available_balance_minor', 'collection_callback_url', 'disbursement_callback_url', 'callback_header_name', 'callback_header_value', 'last_synced_at'])]
class IotecWallet extends Model
{
    /** @use HasFactory<IotecWalletFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the payments made through this wallet.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'wallet_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'callback_header_value' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Nullable by design: rows are created platform-level before any team
     * context exists (docs/02 §3) — hence no BelongsToTeam trait.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
