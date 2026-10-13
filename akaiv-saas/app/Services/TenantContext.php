<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Closure;

class TenantContext
{
    private ?int $workerOrganization = null;

    public function id(?User $user = null): ?int
    {
        if ($this->workerOrganization !== null) {
            return $this->workerOrganization;
        }
        $user ??= auth()->user();
        if (! $user) {
            return null;
        }
        $requested = session('active_organization_id');
        $query = $user->organizations()->where('is_suspended', false);
        if ($requested !== null) {
            $id = $query->where('organizations.id', $requested)->value('organizations.id');
        } else {
            $id = $query->orderBy('organizations.id')->value('organizations.id');
            if ($id !== null) {
                session()->put('active_organization_id', $id);
            }
        }

        return $id === null ? null : (int) $id;
    }

    public function member(User $user, int $organizationId): bool
    {
        return $this->id($user) === $organizationId && $user->organizations()->where('organizations.id', $organizationId)->where('is_suspended', false)->exists();
    }

    public function canWrite(User $user, int $organizationId): bool
    {
        if (! $this->member($user, $organizationId)) {
            return false;
        }
        $membership = $user->organizations()->where('organizations.id', $organizationId)->first();

        return in_array($membership?->pivot?->role, ['owner', 'workspace_manager', 'member_write'], true);
    }

    public function run(int $organizationId, Closure $operation): mixed
    {
        abort_unless(Organization::whereKey($organizationId)->where('is_suspended', false)->exists(), 403);
        $previous = $this->workerOrganization;
        $this->workerOrganization = $organizationId;
        try {
            return $operation();
        } finally {
            $this->workerOrganization = $previous;
        }
    }
}
