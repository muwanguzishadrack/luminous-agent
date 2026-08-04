<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Support\Facades\Teams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer 1 of team isolation (docs/05 §1): a global scope on every read and a
 * required team_id on every write, with no silent default.
 */
trait BelongsToTeam
{
    protected static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope);

        static::creating(function (Model $model) {
            if ($model->getAttribute('team_id') === null) {
                $model->setAttribute('team_id', Teams::currentIdOrFail());
            }
        });
    }

    /**
     * Get the team that owns the model.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
