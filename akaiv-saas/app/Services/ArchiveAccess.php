<?php

namespace App\Services;

use App\Models\CaseFile;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveAccess
{
    public function case(User $user, CaseFile $case): bool
    {
        if (! app(TenantContext::class)->member($user, (int) $case->organization_id) || $case->trashed()) {
            return false;
        }
        if (! $case->is_sealed) {
            return true;
        }

        return DB::table('case_access_grants')->where('case_id', $case->id)->where('user_id', $user->id)->exists();
    }

    public function document(User $user, Document $document, bool $allowDeleted = false): bool
    {
        if (! app(TenantContext::class)->member($user, (int) $document->organization_id) || (! $allowDeleted && $document->trashed())) {
            return false;
        }
        if ($document->folder_id && (! $document->folder || ! $this->folder($user, $document->folder))) {
            return false;
        }
        if ($document->case_id !== null && (! $document->case || ! $this->case($user, $document->case))) {
            return false;
        }
        if ($document->proceeding_id !== null && (! $document->proceeding || ! $document->proceeding->case || ! $this->case($user, $document->proceeding->case))) {
            return false;
        }

        return true;
    }

    public function folder(User $user, Folder $folder): bool
    {
        if (! app(TenantContext::class)->member($user, (int) $folder->organization_id)) {
            return false;
        }
        $seen = [];
        while ($folder) {
            if ($folder->trashed() || isset($seen[$folder->id]) || (int) $folder->organization_id !== app(TenantContext::class)->id($user)) {
                return false;
            }
            $seen[$folder->id] = true;
            if ($folder->case_id) {
                $case = CaseFile::withoutGlobalScopes()->find($folder->case_id);
                if (! $case || ! $this->case($user, $case)) {
                    return false;
                }
            }
            $folder = $folder->parent_folder_id ? Folder::withoutGlobalScopes()->find($folder->parent_folder_id) : null;
        }

        return true;
    }

    public function eligible(Document $document): bool
    {
        return ! $document->trashed() && $document->status === 'published' && $document->virus_scanned
            && ! $document->virus_found && $document->scan_state === 'clean' && filled($document->sha256_checksum);
    }
}
