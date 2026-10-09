<?php

namespace App\Models;

use App\Concerns\AuditsArchiveRecords;
use App\Concerns\BelongsToOrganization;
use App\Scopes\ArchiveVisibilityScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;

class CaseProceeding extends Model
{
    use AuditsArchiveRecords;
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['organization_id', 'case_id', 'session_date', 'presiding_judge', 'summary_notes', 'status', 'created_by', 'updated_by'];

    protected $casts = ['session_date' => 'date'];

    public function case()
    {
        return $this->belongsTo(CaseFile::class, 'case_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'proceeding_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ArchiveVisibilityScope);
        static::saving(function (self $record) {
            $case = CaseFile::withoutTenancy()->findOrFail($record->case_id);
            abort_unless((int) $case->organization_id === (int) $record->organization_id, 422);
            if (auth()->check()) {
                Gate::authorize('update', $case);
                if ($record->exists) {
                    $record->updated_by = auth()->id();
                }
            }
        });
    }
}
