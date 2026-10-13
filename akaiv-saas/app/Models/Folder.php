<?php

namespace App\Models;

use App\Concerns\AuditsArchiveRecords;
use App\Concerns\BelongsToOrganization;
use App\Scopes\ArchiveVisibilityScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Folder extends Model
{
    use AuditsArchiveRecords;
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'parent_folder_id',
        'case_id',
        'name',
        'depth',
        'path_cache',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'depth' => 'integer',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ArchiveVisibilityScope);
        static::saving(function (self $folder) {
            foreach (['case_id' => CaseFile::class, 'workspace_id' => Workspace::class] as $key => $class) {
                if ($folder->$key && ! $class::withoutGlobalScopes()->whereKey($folder->$key)->where('organization_id', $folder->organization_id)->whereNull('deleted_at')->exists()) {
                    abort(422);
                }
            }
            if ($folder->parent_folder_id) {
                $parent = self::withoutGlobalScopes()->findOrFail($folder->parent_folder_id);
                abort_unless((int) $parent->organization_id === (int) $folder->organization_id && $parent->id !== $folder->id, 422);
                $seen = [$folder->id];
                while ($parent) {
                    abort_if(in_array($parent->id, $seen, true), 422, 'Folder cycle');
                    $seen[] = $parent->id;
                    $parent = $parent->parent_folder_id ? self::withoutGlobalScopes()->find($parent->parent_folder_id) : null;
                }
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_folder_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_folder_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseFile::class, 'case_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function pathSegments(): array
    {
        if ($this->path_cache !== null && $this->path_cache !== '') {
            return explode('/', $this->path_cache);
        }
        $segments = [$this->name];
        $current = $this->parent;
        while ($current !== null) {
            array_unshift($segments, $current->name);
            $current = $current->parent;
        }

        return $segments;
    }
}
