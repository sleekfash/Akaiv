<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Share extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'token',
        'document_id',
        'shared_by',
        'recipient_email',
        'allowed_ips_csv',
        'password_hash',
        'max_accesses',
        'access_count',
        'can_download',
        'can_preview',
        'expires_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'max_accesses' => 'integer',
        'access_count' => 'integer',
        'can_download' => 'boolean',
        'can_preview' => 'boolean',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $share) {
            if (empty($share->token)) {
                $share->token = (string) Str::ulid();
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    public function hasPassword(): bool
    {
        return ! empty($this->password_hash);
    }

    public function checkPassword(string $password): bool
    {
        if (! $this->hasPassword()) {
            return true;
        }

        return password_verify($password, $this->password_hash);
    }

    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function isIpAllowed(string $ip): bool
    {
        if (empty($this->allowed_ips_csv)) {
            return true;
        }
        $allowed = array_map('trim', explode(',', $this->allowed_ips_csv));

        return in_array($ip, $allowed, true);
    }

    public function hasAccessLimitReached(): bool
    {
        if ($this->max_accesses === null) {
            return false;
        }

        return $this->access_count >= $this->max_accesses;
    }

    public function isAccessible(string $ip, ?string $password = null): bool
    {
        if ($this->isExpired()) {
            return false;
        }
        if ($this->hasAccessLimitReached()) {
            return false;
        }
        if (! $this->isIpAllowed($ip)) {
            return false;
        }

        return $this->checkPassword($password ?? '');
    }

    public function recordAccess(): void
    {
        $this->forceFill([
            'access_count' => ($this->access_count + 1),
            'last_accessed_at' => now(),
        ])->save();
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
