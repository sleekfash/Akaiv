<?php

namespace App\Models;

use App\Concerns\AuditsArchiveRecords;
use App\Concerns\BelongsToOrganization;
use App\Scopes\ArchiveVisibilityScope;
use App\Services\ArchiveAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;

class Document extends Model implements HasMedia
{
    use AuditsArchiveRecords;
    use BelongsToOrganization;
    use HasFactory;
    use HasTags;
    use InteractsWithMedia;
    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    protected $fillable = [
        'proceeding_id',
        'date_delivered',
        'judicial_document_type',
        'scan_state',
        'file_revision',
        'uuid',
        'organization_id',
        'workspace_id',
        'folder_id',
        'case_id',
        'document_type_id',
        'owner_id',
        'uploaded_by',
        'friendly_name',
        'original_filename',
        'slug',
        'storage_disk',
        'storage_path',
        'size_bytes',
        'mime_type',
        'file_extension',
        'sha256_checksum',
        'folio_number',
        'description',
        'extracted_text',
        'page_count',
        'metadata',
        'status',
        'ocr_required',
        'ocr_completed',
        'virus_scanned',
        'virus_found',
        'virus_scanned_at',
        'retention_date',
        'retention_policy',
        'download_count',
        'view_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'date_delivered' => 'date',
        'size_bytes' => 'integer',
        'metadata' => 'array',
        'ocr_required' => 'boolean',
        'ocr_completed' => 'boolean',
        'virus_scanned' => 'boolean',
        'virus_found' => 'boolean',
        'virus_scanned_at' => 'datetime',
        'retention_date' => 'date',
        'download_count' => 'integer',
        'view_count' => 'integer',
        'last_accessed_at' => 'datetime',
        'page_count' => 'integer',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope(new ArchiveVisibilityScope);
        static::saving(function (self $document) {
            if ($document->case_id && $document->proceeding_id) {
                abort(422, 'Choose one parent.');
            }
            foreach (['case_id' => CaseFile::class, 'proceeding_id' => CaseProceeding::class, 'folder_id' => Folder::class, 'workspace_id' => Workspace::class] as $field => $class) {
                if ($document->$field && ! $class::withoutGlobalScopes()->whereKey($document->$field)->where('organization_id', $document->organization_id)->whereNull('deleted_at')->exists()) {
                    abort(422, 'Invalid parent tenant.');
                }
            }
        });
        static::creating(function (self $document) {
            if (empty($document->uuid)) {
                $document->uuid = (string) Str::uuid();
            }
            if (empty($document->slug) && ! empty($document->friendly_name)) {
                $document->slug = Str::slug($document->friendly_name);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'friendly_name',
                'folder_id',
                'case_id',
                'document_type_id',
                'status',
                'description',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function shouldBeSearchable(): bool
    {
        return false;
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'friendly_name' => $this->friendly_name,
            'original_filename' => $this->original_filename,
            'folio_number' => $this->folio_number,
            'description' => $this->description,
            'extracted_text' => '',
            'status' => $this->status,
            'organization_id' => $this->organization_id,
            'folder_id' => $this->folder_id,
            'case_id' => $this->case_id,
            'document_type_id' => $this->document_type_id,
            'owner_id' => $this->owner_id,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->where('status', '!=', 'deleted')
            ->whereNotNull('organization_id');
    }

    public function proceeding()
    {
        return $this->belongsTo(CaseProceeding::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseFile::class, 'case_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    public function documentTags(): HasMany
    {
        return $this->hasMany(DocumentTag::class);
    }

    public function activity(): MorphMany
    {
        return $this->morphMany(config('activitylog.activity_model', Activity::class), 'subject');
    }

    public function incrementDownloadCount(): void
    {
        $this->forceFill([
            'download_count' => ($this->download_count + 1),
            'last_accessed_at' => now(),
        ])->save();
    }

    public function incrementViewCount(): void
    {
        $this->forceFill([
            'view_count' => ($this->view_count + 1),
            'last_accessed_at' => now(),
        ])->save();
    }

    public function isViewableBy(User $user): bool
    {
        return app(ArchiveAccess::class)->document($user, $this);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
