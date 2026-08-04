<?php

namespace App\Models\Scopes;

use App\Support\Facades\Teams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TeamScope implements Scope
{
    /**
     * Constrain every query to the current team. When no context is
     * established the query matches nothing — never everything.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $teamId = Teams::currentId();

        if ($teamId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('team_id'), $teamId);
    }
}
