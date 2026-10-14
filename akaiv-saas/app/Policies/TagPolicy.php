<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;
use App\Services\TenantContext;

class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return app(TenantContext::class)->id($user) !== null;
    }

    public function view(User $user, Tag $tag): bool
    {
        return app(TenantContext::class)->member($user, $tag->organization_id);
    }

    public function create(User $user): bool
    {
        $id = app(TenantContext::class)->id($user);

        return $id !== null && app(TenantContext::class)->canWrite($user, $id) && $user->checkPermissionTo('tag.create');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->view($user, $tag) && $this->create($user);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }
}
