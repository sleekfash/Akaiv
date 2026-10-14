<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('Platform SuperAdmin')) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->organizations()->exists();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->isMember($user, $organization);
    }

    public function create(User $user): bool
    {
        return $user->can('create_organization');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->isOwner($user, $organization)
            || ($this->isMember($user, $organization) && $user->can('update_organization'));
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->isOwner($user, $organization) && $user->can('delete_organization');
    }

    private function isMember(User $user, Organization $organization): bool
    {
        return $user->organizations()->where('organizations.id', $organization->id)->exists();
    }

    private function isOwner(User $user, Organization $organization): bool
    {
        return (int) $organization->owner_user_id === (int) $user->id;
    }
}
