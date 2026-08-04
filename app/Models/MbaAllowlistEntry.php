<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property string $mba_agent_id
 * @property string $wa_id
 * @property string|null $added_by
 * @property Carbon $added_at
 * @property Carbon|null $removed_at
 * @property-read Team $team
 * @property-read MbaAgent $mbaAgent
 */
#[Fillable(['mba_agent_id', 'wa_id', 'added_by', 'added_at', 'removed_at'])]
class MbaAllowlistEntry extends Model
{
    use BelongsToTeam, HasUuids;

    /**
     * The table carries no created_at / updated_at pair.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the agent this allowlist entry belongs to.
     *
     * @return BelongsTo<MbaAgent, $this>
     */
    public function mbaAgent(): BelongsTo
    {
        return $this->belongsTo(MbaAgent::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }
}
