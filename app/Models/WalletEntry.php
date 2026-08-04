<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Team billing ledger, append-only — balance is always SUM(amount_minor);
 * balance_after_minor is a cached checkpoint, never trusted alone
 * (docs/02-data-model.md §11).
 *
 * @property int $id
 * @property string $team_id
 * @property string $kind
 * @property int $amount_minor
 * @property string $currency
 * @property int $balance_after_minor
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property-read Team $team
 */
#[Fillable(['kind', 'amount_minor', 'currency', 'balance_after_minor', 'reference_type', 'reference_id', 'description'])]
class WalletEntry extends Model
{
    use BelongsToTeam;

    /**
     * The table only carries created_at.
     */
    const UPDATED_AT = null;
}
