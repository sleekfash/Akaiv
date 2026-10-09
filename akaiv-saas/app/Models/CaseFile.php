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

class CaseFile extends Model
{
    use AuditsArchiveRecords;
    use BelongsToOrganization;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'cases';

    protected $fillable = [
        'organization_id',
        'normalized_suit_number',
        'subject_matter',
        'is_sealed',
        'workspace_id',
        'case_number',
        'suit_number',
        'title',
        'parties_json',
        'court_name',
        'bench_judge_name',
        'jurisdiction',
        'notes',
        'date_filed',
        'date_judgment',
        'status',
        'created_by',
    ];

    protected $casts = [
        'is_sealed' => 'boolean',
        'date_filed' => 'date',
        'date_judgment' => 'date',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ArchiveVisibilityScope);
        static::saving(function (self $case) {
            if (! $case->exists && auth()->check()) {
                $case->created_by = auth()->id();
            }
            if ($case->exists && $case->isDirty('status')) {
                $allowed = ['open' => ['closed'], 'closed' => ['open', 'archived'], 'archived' => []];
                abort_unless(in_array($case->status, $allowed[$case->getOriginal('status')] ?? [], true) && auth()->user()?->checkPermissionTo('case.lifecycle'), 422, 'Invalid case lifecycle transition');
            }
            $case->normalized_suit_number = filled($case->suit_number) ? strtoupper(preg_replace('/\s+/u', '', trim($case->suit_number))) : null;
            if ($case->workspace_id) {
                abort_unless(Workspace::withoutTenancy()->whereKey($case->workspace_id)->where('organization_id', $case->organization_id)->exists(), 422);
            }
        });
    }

    public function proceedings()
    {
        return $this->hasMany(CaseProceeding::class, 'case_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'case_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'case_id');
    }

    public function getPartiesAttribute(): array
    {
        if ($this->parties_json === null) {
            return [];
        }

        return json_decode($this->parties_json, true) ?? [];
    }

    public function setPartiesAttribute(array $parties): void
    {
        $this->attributes['parties_json'] = json_encode($parties);
    }
}
