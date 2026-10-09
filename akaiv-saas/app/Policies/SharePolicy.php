<?php

namespace App\Policies;

use App\Models\Share;
use App\Models\User;
use App\Services\TenantContext;

class SharePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function view(User $user, Share $share): bool
    {
        return $this->assertOrg($user, $share) && (
            $user->checkPermissionTo('share.view_any') ||
            (int) $share->shared_by === (int) $user->id
        );
    }

    public function create(User $user): bool
    {
        return ($id = app(TenantContext::class)->id($user)) !== null
            && app(TenantContext::class)->canWrite($user, $id)
            && $user->checkPermissionTo('share.create');
    }

    public function update(User $user, Share $share): bool
    {
        return $this->assertOrg($user, $share) && (
            $user->checkPermissionTo('share.update_any') ||
            (int) $share->shared_by === (int) $user->id
        );
    }

    public function delete(User $user, Share $share): bool
    {
        return $this->assertOrg($user, $share) && (
            $user->checkPermissionTo('share.delete_any') ||
            (int) $share->shared_by === (int) $user->id
        );
    }

    private function assertOrg(User $user, Share $share): bool
    {
        $activeOrg = app(TenantContext::class)->id($user);
        $document = $share->document;

        if ($activeOrg === null || $document === null) {
            return false;
        }

        return (int) $document->organization_id === (int) $activeOrg;
    }
}
