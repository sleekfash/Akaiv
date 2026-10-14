<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\ArchiveAccess;
use App\Services\TenantContext;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->id($user) !== null;
    }

    public function view(User $user, Document $document): bool
    {
        return app(ArchiveAccess::class)->document($user, $document) && (
            $user->checkPermissionTo('document.view') ||
            $document->owner_id === $user->id ||
            $user->checkPermissionTo('document.view_any')
        );
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document) && app(ArchiveAccess::class)->eligible($document) && $user->checkPermissionTo('document.download');
    }

    public function create(User $user): bool
    {
        return ($id = app(TenantContext::class)->id($user)) !== null
            && app(TenantContext::class)->canWrite($user, $id)
            && $user->checkPermissionTo('document.create');
    }

    public function update(User $user, Document $document): bool
    {
        return $document->status === 'draft' && app(ArchiveAccess::class)->document($user, $document) && app(TenantContext::class)->canWrite($user, (int) $document->organization_id) && (
            app(TenantContext::class)->canWrite($user, (int) $document->organization_id) && $user->checkPermissionTo('document.update_any') ||
            ($user->checkPermissionTo('document.update_own') && $document->owner_id === $user->id)
        );
    }

    public function delete(User $user, Document $document): bool
    {
        return app(ArchiveAccess::class)->document($user, $document) && app(TenantContext::class)->canWrite($user, (int) $document->organization_id) && (
            app(TenantContext::class)->canWrite($user, (int) $document->organization_id) && $user->checkPermissionTo('document.delete_any') ||
            ($user->checkPermissionTo('document.delete_own') && $document->owner_id === $user->id)
        );
    }

    public function restore(User $user, Document $document): bool
    {
        return app(ArchiveAccess::class)->document($user, $document, true)
            && app(TenantContext::class)->canWrite($user, $document->organization_id)
            && $user->checkPermissionTo('document.restore');
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return false;
    }

    private function assertOrg(User $user, Document $document): bool
    {
        $activeOrg = app(TenantContext::class)->id($user);

        return $activeOrg !== null && (int) $document->organization_id === (int) $activeOrg;
    }
}
