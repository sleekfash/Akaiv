<?php

namespace App\Providers;

use App\Models\Document;
use App\Observers\DocumentObserver;
use App\Services\ArchiveAudit;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Event::listen([
            RoleAttached::class,
            RoleDetached::class,
            PermissionAttached::class,
            PermissionDetached::class,
        ], function ($event) {
            $ids = collect($event->rolesOrIds ?? $event->permissionsOrIds ?? [])->map(fn ($item) => $item instanceof Model ? $item->getKey() : $item)->all();
            app(ArchiveAudit::class)->append(0, auth()->id(), strtoupper(class_basename($event)), get_class($event->model), (string) $event->model->getKey(), ['ids' => $ids]);
        });

        if (class_exists(Document::class) && class_exists(DocumentObserver::class)) {
            Document::observe(DocumentObserver::class);
        }
    }
}
