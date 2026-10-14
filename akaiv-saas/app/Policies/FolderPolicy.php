<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;
use App\Services\ArchiveAccess;
use App\Services\TenantContext;

class FolderPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->id($user) !== null;
    }

    public function view(User $user, Folder $folder): bool
    {
        return app(ArchiveAccess::class)->folder($user, $folder) && (
            $user->checkPermissionTo('folder.view') ||
            $user->checkPermissionTo('folder.view_any') ||
            (int) $folder->created_by === (int) $user->id
        );
    }

    public function create(User $user): bool
    {
        return ($id = app(TenantContext::class)->id($user)) !== null
            && app(TenantContext::class)->canWrite($user, $id)
            && $user->checkPermissionTo('folder.create');
    }

    public function update(User $user, Folder $folder): bool
    {
        return app(ArchiveAccess::class)->folder($user, $folder) && app(TenantContext::class)->canWrite($user, (int) $folder->organization_id) && (
            $user->checkPermissionTo('folder.update_any') ||
            ($user->checkPermissionTo('folder.update_own') && (int) $folder->created_by === (int) $user->id)
        );
    }

    public function delete(User $user, Folder $folder): bool
    {
        return app(ArchiveAccess::class)->folder($user, $folder) && app(TenantContext::class)->canWrite($user, (int) $folder->organization_id) && (
            $user->checkPermissionTo('folder.delete_any') ||
            ($user->checkPermissionTo('folder.delete_own') && (int) $folder->created_by === (int) $user->id)
        );
    }

    private function assertOrg(User $user, Folder $folder): bool
    {
        $activeOrg = app(TenantContext::class)->id($user);

        return $activeOrg !== null && (int) $folder->organization_id === (int) $activeOrg;
    }
}
