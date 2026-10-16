<?php

namespace App\Services;

use App\Models\CaseProceeding;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ArchiveWorkflow
{
    public function transition(Document $document, User $actor, string $next, string $expected): Document
    {
        return DB::transaction(function () use ($document, $actor, $next, $expected) {
            $record = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ArchiveAccess::class)->document($actor, $record) && app(TenantContext::class)->canWrite($actor, $record->organization_id), 403);
            $transitions = ['draft' => ['pending_review'], 'pending_review' => ['draft', 'published'], 'published' => ['archived'], 'archived' => []];
            if ($record->status !== $expected || ! in_array($next, $transitions[$expected] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'The transition is invalid or the record changed.']);
            }
            $permission = $next === 'pending_review' ? 'document.submit' : 'document.review';
            abort_unless($actor->checkPermissionTo($permission), 403);
            if ($next === 'published') {
                abort_if((int) ($record->last_modified_by ?? $record->uploaded_by) === (int) $actor->id, 403, 'A separate reviewer must publish.');
                $case = $record->case ?? $record->proceeding?->case;
                if (! $case || ! filled($case->suit_number) || ! filled($case->subject_matter)
                    || ! filled($record->judicial_document_type) || ($record->judicial_document_type !== 'transcript' && ! $record->date_delivered)
                    || $record->scan_state !== 'clean' || ! $record->virus_scanned || $record->virus_found || ! $record->sha256_checksum) {
                    throw ValidationException::withMessages(['status' => 'Complete metadata and a verified clean file are required before publishing.']);
                }
            }
            $record->forceFill(['status' => $next])->saveQuietly();
            app(ArchiveAudit::class)->append($record->organization_id, $actor->id, 'DOCUMENT_TRANSITION', Document::class, (string) $record->id, ['from' => $expected, 'to' => $next]);
            DB::table('domain_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $record->organization_id, 'event_type' => 'document.status_changed', 'payload' => json_encode(['id' => $record->uuid, 'status' => $next], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $record;
        });
    }

    public function publishProceeding(CaseProceeding $proceeding, User $actor): void
    {
        DB::transaction(function () use ($proceeding, $actor) {
            $record = CaseProceeding::whereKey($proceeding->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $record);
            abort_unless($actor->checkPermissionTo('document.review') && (int) ($record->updated_by ?? $record->created_by) !== $actor->id, 403);
            $record->forceFill(['status' => 'published'])->saveQuietly();
            app(ArchiveAudit::class)->append($record->organization_id, $actor->id, 'PROCEEDING_PUBLISHED', CaseProceeding::class, (string) $record->id);
        });
    }
}
