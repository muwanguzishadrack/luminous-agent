<?php

namespace App\Models\Scopes;

use App\Support\Facades\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * Constrain every query to the current tenant. When no context is
     * established the query matches nothing — never everything.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = Tenancy::currentId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
