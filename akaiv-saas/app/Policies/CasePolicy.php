<?php

namespace App\Policies;

use App\Models\CaseFile;
use App\Models\User;
use App\Services\ArchiveAccess;
use App\Services\TenantContext;

class CasePolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->id($user) !== null;
    }

    public function view(User $user, CaseFile $case): bool
    {
        return app(ArchiveAccess::class)->case($user, $case) && (
            $user->checkPermissionTo('case.view') ||
            $user->checkPermissionTo('case.view_any') ||
            (int) $case->created_by === (int) $user->id
        );
    }

    public function create(User $user): bool
    {
        return ($id = app(TenantContext::class)->id($user)) !== null
            && app(TenantContext::class)->canWrite($user, $id)
            && $user->checkPermissionTo('case.create');
    }

    public function update(User $user, CaseFile $case): bool
    {
        return app(ArchiveAccess::class)->case($user, $case) && app(TenantContext::class)->canWrite($user, (int) $case->organization_id) && (
            app(TenantContext::class)->canWrite($user, (int) $case->organization_id) && $user->checkPermissionTo('case.update_any') ||
            ($user->checkPermissionTo('case.update_own') && (int) $case->created_by === (int) $user->id)
        );
    }

    public function delete(User $user, CaseFile $case): bool
    {
        return app(ArchiveAccess::class)->case($user, $case) && app(TenantContext::class)->canWrite($user, (int) $case->organization_id) && (
            app(TenantContext::class)->canWrite($user, (int) $case->organization_id) && $user->checkPermissionTo('case.delete_any') ||
            ($user->checkPermissionTo('case.delete_own') && (int) $case->created_by === (int) $user->id)
        );
    }

    private function assertOrg(User $user, CaseFile $case): bool
    {
        $activeOrg = app(TenantContext::class)->id($user);

        return $activeOrg !== null && (int) $case->organization_id === (int) $activeOrg;
    }
}
