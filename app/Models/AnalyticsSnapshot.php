<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached pulls from Meta so dashboards do not hammer the Graph API
 * (docs/02-data-model.md §11).
 *
 * @property string $id
 * @property string $team_id
 * @property string $waba_account_id
 * @property string $field
 * @property string $granularity
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property array<string, mixed> $dimensions
 * @property string $dimensions_hash
 * @property array<string, mixed> $payload
 * @property Carbon $fetched_at
 * @property-read Team $team
 * @property-read WabaAccount $wabaAccount
 */
#[Fillable(['waba_account_id', 'field', 'granularity', 'start_at', 'end_at', 'dimensions', 'dimensions_hash', 'payload', 'fetched_at'])]
class AnalyticsSnapshot extends Model
{
    use BelongsToTeam, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the WABA this snapshot was pulled for.
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
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'dimensions' => 'array',
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }
}
