<?php

namespace App\Concerns;

use App\Models\Organization;
use App\Scopes\OrganizationScope;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->isFillable('created_by') && auth()->check()) {
                $model->created_by = auth()->id();
            }
            if (! $model->organization_id) {
                $model->organization_id = app(TenantContext::class)->id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
