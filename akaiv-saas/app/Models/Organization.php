<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Cashier\Billable;

class Organization extends Model
{
    use Billable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'registration_number',
        'court_type',
        'jurisdiction_state',
        'contact_email',
        'contact_phone',
        'address',
        'plan',
        'storage_quota_bytes',
        'storage_used_bytes',
        'is_suspended',
        'trial_ends_at',
        'grace_period_ends_at',
        'owner_user_id',
    ];

    protected $casts = [
        'storage_quota_bytes' => 'integer',
        'storage_used_bytes' => 'integer',
        'is_suspended' => 'boolean',
        'trial_ends_at' => 'datetime',
        'grace_period_ends_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['role', 'invited_at', 'joined_at', 'invited_by'])
            ->withTimestamps();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(CaseFile::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function storageUsagePercent(): float
    {
        if ($this->storage_quota_bytes <= 0) {
            return 100.0;
        }

        return min(100.0, ($this->storage_used_bytes / $this->storage_quota_bytes) * 100);
    }

    public function hasCapacityFor(int $bytes): bool
    {
        return ($this->storage_used_bytes + $bytes) <= $this->storage_quota_bytes;
    }
}
