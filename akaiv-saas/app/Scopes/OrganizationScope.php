<?php

namespace App\Scopes;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(TenantContext::class)->id();
        $id === null ? $builder->whereRaw('1 = 0') : $builder->where($model->qualifyColumn('organization_id'), $id);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenancy', fn (Builder $builder) => $builder->withoutGlobalScope($this));
    }
}
