<?php

namespace App\Services;

use App\Models\CaseFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SealedCases
{
    public function seal(CaseFile $case, User $actor, array $userIds): void
    {
        DB::transaction(function () use ($case, $actor, $userIds) {
            $record = CaseFile::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(ArchiveAccess::class)->case($actor, $record) && app(TenantContext::class)->canWrite($actor, $record->organization_id) && $actor->checkPermissionTo('case.seal'), 403);
            $ids = array_values(array_unique(array_map('intval', $userIds)));
            abort_unless(count($ids) > 0, 422, 'Explicit sealed access grants are required.');
            foreach ($ids as $id) {
                abort_unless(DB::table('organization_user')->where('organization_id', $record->organization_id)->where('user_id', $id)->exists(), 422);
            }
            $record->forceFill(['is_sealed' => true])->saveQuietly();
            DB::table('case_access_grants')->where('case_id', $record->id)->delete();
            foreach ($ids as $id) {
                DB::table('case_access_grants')->insert(['case_id' => $record->id, 'user_id' => $id, 'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(ArchiveAudit::class)->append($record->organization_id, $actor->id, 'CASE_SEALED_GRANTS_SET', CaseFile::class, (string) $record->id, ['granted_user_ids' => $ids]);
        });
    }
}
