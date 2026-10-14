<?php

namespace App\Providers;

use App\Models\CaseFile;
use App\Models\CaseProceeding;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\Share;
use App\Models\Tag;
use App\Models\User;
use App\Policies\CasePolicy;
use App\Policies\DocumentPolicy;
use App\Policies\FolderPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProceedingPolicy;
use App\Policies\RolePolicy;
use App\Policies\SharePolicy;
use App\Policies\TagPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Spatie\Permission\Models\Role;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Tag::class => TagPolicy::class,
        Role::class => RolePolicy::class,
        User::class => UserPolicy::class,
        CaseProceeding::class => ProceedingPolicy::class,
        Document::class => DocumentPolicy::class,
        Folder::class => FolderPolicy::class,
        CaseFile::class => CasePolicy::class,
        Share::class => SharePolicy::class,
        Organization::class => OrganizationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
