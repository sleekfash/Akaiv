<?php

namespace App\Scopes;

use App\Models\CaseFile;
use App\Models\Document;
use App\Models\Folder;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ArchiveVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        } // Explicit worker tenant context is still required by OrganizationScope.
        if ($model instanceof CaseFile) {
            $builder->where(function (Builder $query) use ($user) {
                $query->where('is_sealed', false)->orWhereExists(function ($grants) use ($user) {
                    $grants->selectRaw('1')->from('case_access_grants')->whereColumn('case_access_grants.case_id', 'cases.id')->where('user_id', $user->id);
                });
            });
        } elseif ($model instanceof Document) {
            if (! app(TenantContext::class)->canWrite($user, (int) app(TenantContext::class)->id($user))) {
                $builder->where('status', 'published');
            }
            $builder->where(fn (Builder $q) => $q->whereNull('folder_id')->orWhereHas('folder'));
            $builder->where(fn (Builder $q) => $q->whereNull('case_id')->orWhereHas('case'));
            $builder->where(fn (Builder $q) => $q->whereNull('proceeding_id')->orWhereHas('proceeding'));
        } elseif ($model instanceof Folder) {
            $builder->whereRaw('NOT EXISTS (
                WITH RECURSIVE ancestors AS (
                    SELECT id,parent_folder_id,case_id,organization_id,deleted_at FROM folders AS origin WHERE origin.id = folders.id
                    UNION
                    SELECT parent.id,parent.parent_folder_id,parent.case_id,parent.organization_id,parent.deleted_at FROM folders parent JOIN ancestors child ON parent.id = child.parent_folder_id
                )
                SELECT 1 FROM ancestors LEFT JOIN cases ON cases.id = ancestors.case_id
                WHERE ancestors.organization_id <> ? OR ancestors.deleted_at IS NOT NULL
                    OR (ancestors.case_id IS NOT NULL AND (cases.id IS NULL OR cases.deleted_at IS NOT NULL OR (cases.is_sealed = true AND NOT EXISTS (
                        SELECT 1 FROM case_access_grants WHERE case_access_grants.case_id = cases.id AND case_access_grants.user_id = ?
                    ))))
            )', [app(TenantContext::class)->id($user), $user->id]);
        } else {
            if (! app(TenantContext::class)->canWrite($user, (int) app(TenantContext::class)->id($user))) {
                $builder->where('status', 'published');
            }
            $builder->whereHas('case');
        }
    }
}
