<?php

namespace App\Policies;

use App\Models\CaseProceeding;
use App\Models\User;
use App\Services\ArchiveAccess;
use App\Services\TenantContext;

class ProceedingPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->id($user) !== null;
    }

    public function view(User $user, CaseProceeding $record): bool
    {
        return $record->case && app(ArchiveAccess::class)->case($user, $record->case);
    }

    public function create(User $user): bool
    {
        $id = app(TenantContext::class)->id($user);

        return $id !== null && app(TenantContext::class)->canWrite($user, $id) && ($user->checkPermissionTo('case.update_any') || $user->checkPermissionTo('case.update_own'));
    }

    public function update(User $user, CaseProceeding $record): bool
    {
        return $this->view($user, $record) && $this->create($user) && $record->status === 'draft';
    }

    public function delete(User $user, CaseProceeding $record): bool
    {
        return $this->update($user, $record);
    }
}
