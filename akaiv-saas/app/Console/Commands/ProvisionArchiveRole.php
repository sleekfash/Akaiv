<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ArchiveAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ProvisionArchiveRole extends Command
{
    protected $signature = 'archive:provision-role {email} {role : Clerk, Judge, Administrator or Platform SuperAdmin}';

    protected $description = 'Explicit operator provisioning of an existing account; no first-signup administrator';

    public function handle(): int
    {
        $roles = [
            'Clerk' => ['case.view', 'case.create', 'case.update_own', 'document.view', 'document.create', 'document.update_own', 'document.submit', 'document.download', 'folder.view', 'folder.create', 'folder.update_own'],
            'Judge' => ['case.view_any', 'case.create', 'case.update_any', 'document.view_any', 'document.create', 'document.update_any', 'document.review', 'document.download', 'archive.audit'],
            'Administrator' => ['case.view_any', 'case.create', 'case.update_any', 'case.seal', 'case.lifecycle', 'document.view_any', 'document.create', 'document.update_any', 'document.delete_any', 'document.restore', 'document.submit', 'document.review', 'document.download', 'folder.view_any', 'folder.create', 'folder.update_any', 'archive.import', 'archive.export', 'archive.audit'],
            'Platform SuperAdmin' => [],
        ];
        if (! isset($roles[$this->argument('role')])) {
            return self::FAILURE;
        }
        $user = User::where('email', $this->argument('email'))->firstOrFail();
        DB::transaction(function () use ($user, $roles) {
            $role = Role::findOrCreate($this->argument('role'), 'web');
            foreach (array_unique(array_merge(...array_values($roles))) as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            $role->syncPermissions($roles[$this->argument('role')]);
            $user->assignRole($role);
            app(ArchiveAudit::class)->append(0, null, 'OPERATOR_ROLE_PROVISIONED', User::class, (string) $user->id, ['role' => $role->name]);
        });
        $this->info('Role provisioned. Organization membership is separately required; sealed grants are never implicit.');

        return self::SUCCESS;
    }
}
