<?php

namespace App\Services;

use App\Models\CaseFile;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImportCommit
{
    public function item(int $itemId, User $actor, string $root, array $metadata): ?Document
    {
        abort_unless($actor->checkPermissionTo('archive.import'), 403);
        $root = realpath($root);
        abort_unless($root && is_dir($root), 422);
        $incoming = null;
        $createdPath = null;
        try {
            return DB::transaction(function () use ($itemId, $actor, $root, $metadata, &$incoming, &$createdPath) {
                $item = DB::table('import_items')->where('id', $itemId)->lockForUpdate()->first();
                abort_unless($item, 404);
                $batch = DB::table('import_batches')->where('id', $item->import_batch_id)->first();
                abort_unless($batch && app(TenantContext::class)->member($actor, $batch->organization_id) && app(TenantContext::class)->canWrite($actor, $batch->organization_id), 403);
                if ($item->status === 'committed') {
                    return Document::find($item->document_id);
                }
                if ($item->status === 'duplicate') {
                    return null;
                }
                Validator::make($metadata, ['case_id' => 'required|integer', 'friendly_name' => 'required|string|max:500', 'judicial_document_type' => 'required|in:judgment,ruling,order,case_file', 'date_delivered' => 'required|date'])->validate();
                $case = CaseFile::findOrFail($metadata['case_id']);
                Gate::forUser($actor)->authorize('view', $case);
                $source = realpath($root.DIRECTORY_SEPARATOR.$item->source_path);
                abort_unless($source && str_starts_with($source, $root.DIRECTORY_SEPARATOR) && is_file($source), 422);
                abort_unless(hash_equals($item->sha256, hash_file('sha256', $source)) && filesize($source) === (int) $item->bytes, 409, 'Source changed since discovery');
                DB::table('organizations')->where('id', $batch->organization_id)->lockForUpdate()->first();
                $duplicate = Document::withoutGlobalScopes()->where('organization_id', $batch->organization_id)->where('sha256_checksum', $item->sha256)->exists();
                if ($duplicate) {
                    DB::table('import_items')->where('id', $itemId)->update(['status' => 'duplicate', 'updated_at' => now()]);
                    app(ArchiveAudit::class)->append($batch->organization_id, $actor->id, 'IMPORT_DUPLICATE', 'import_item', (string) $itemId);

                    return null;
                }
                $incoming = 'org_'.$batch->organization_id.'/incoming/user_'.$actor->id.'/'.Str::uuid().'.'.pathinfo($source, PATHINFO_EXTENSION);
                $stream = fopen($source, 'rb');
                try {
                    abort_unless(Storage::disk('s3')->put($incoming, $stream, ['visibility' => 'private']), 500);
                } finally {
                    fclose($stream);
                }
                $document = app(FileIngestion::class)->save(array_merge($metadata, ['storage_path' => $incoming, 'original_filename' => basename($source)]), $actor);
                $createdPath = $document->storage_path;
                $document->forceFill(['metadata' => ['import_batch_id' => $batch->id, 'import_item_id' => $itemId, 'source_path' => $item->source_path, 'source_sha256' => $item->sha256]])->saveQuietly();
                DB::table('import_items')->where('id', $itemId)->update(['document_id' => $document->id, 'status' => 'committed', 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'error' => null, 'updated_at' => now()]);
                app(ArchiveAudit::class)->append($batch->organization_id, $actor->id, 'IMPORT_CATALOGUED', 'import_item', (string) $itemId, ['document_uuid' => $document->uuid]);

                return $document;
            });
        } catch (\Throwable $e) {
            if ($createdPath && ! Document::withoutGlobalScopes()->where('storage_path', $createdPath)->exists()) {
                Storage::disk('s3')->delete($createdPath);
            }
            throw $e;
        } finally {
            if ($incoming) {
                Storage::disk('s3')->delete($incoming);
            }
        }
    }
}
