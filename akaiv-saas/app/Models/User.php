<?php

namespace App\Models;

use App\Services\ArchiveAudit;
use App\Services\TenantContext;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use Billable;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'remember_token',
        'profile_photo_path',
        'timezone',
        'language',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        foreach (['created', 'updated', 'deleted'] as $action) {
            static::$action(function (self $user) use ($action) {
                app(ArchiveAudit::class)->append(0, auth()->id(), 'USER_'.strtoupper($action), self::class, (string) $user->id, ['changed_fields' => array_keys($user->getChanges())]);
            });
        }
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('Platform SuperAdmin') || app(TenantContext::class)->id($this) !== null;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot(['role', 'invited_at', 'joined_at', 'invited_by'])
            ->withTimestamps();
    }

    public function ownedOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'owner_user_id');
    }

    public function currentOrganization(): ?Organization
    {
        $id = app(TenantContext::class)->id($this);

        return $id === null ? null : $this->organizations()->where('organizations.id', $id)->first();
    }

    public function membershipRoleIn(Organization $organization): ?string
    {
        $pivot = $this->organizations()
            ->where('organization_id', $organization->id)
            ->first()?->pivot;

        return $pivot?->role;
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function ownedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'shared_by');
    }
}
