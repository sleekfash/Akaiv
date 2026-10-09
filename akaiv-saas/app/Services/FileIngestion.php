<?php

namespace App\Services;

use App\Models\CaseFile;
use App\Models\CaseProceeding;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FileIngestion
{
    public function save(array $data, User $actor, ?Document $existing = null): Document
    {
        Gate::forUser($actor)->authorize($existing ? 'update' : 'create', $existing ?? Document::class);
        $org = app(TenantContext::class)->id($actor);
        abort_unless($org !== null, 403);
        $newPath = null;
        try {
            return DB::transaction(function () use ($data, $actor, $existing, $org, &$newPath) {
                if ($existing) {
                    $record = Document::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                    abort_unless($record->status === 'draft', 409, 'Only draft files can be edited.');
                } else {
                    $record = new Document;
                }
                $source = $data['storage_path'] ?? $record->storage_path;
                $metadata = Arr::only($data, ['friendly_name', 'folio_number', 'description', 'retention_date', 'case_id', 'proceeding_id', 'judicial_document_type', 'date_delivered']);
                if (! empty($metadata['case_id'])) {
                    Gate::forUser($actor)->authorize('update', CaseFile::findOrFail($metadata['case_id']));
                }
                if (! empty($metadata['proceeding_id'])) {
                    Gate::forUser($actor)->authorize('update', CaseProceeding::findOrFail($metadata['proceeding_id']));
                }
                Validator::make($metadata, ['friendly_name' => 'sometimes|required|string|max:500', 'judicial_document_type' => 'nullable|in:judgment,ruling,order,case_file,transcript', 'date_delivered' => 'nullable|date', 'description' => 'nullable|string', 'folio_number' => 'nullable|string|max:200'])->validate();
                $record->fill($metadata);
                $record->last_modified_by = $actor->id;
                $record->organization_id = $org;
                if (! $existing || $source !== $record->storage_path) {
                    $disk = Storage::disk('s3');
                    $prefix = 'org_'.$org.'/incoming/user_'.$actor->id.'/';
                    abort_unless(is_string($source) && str_starts_with($source, $prefix) && ! str_contains($source, '..'), 422);
                    $stream = $disk->readStream($source);
                    abort_unless(is_resource($stream), 422);
                    $local = tmpfile();
                    abort_unless(is_resource($local), 500);
                    try {
                        $bytes = stream_copy_to_stream($stream, $local, config('archive.max_upload_bytes') + 1);
                        abort_unless($bytes !== false && $bytes > 0 && $bytes <= config('archive.max_upload_bytes'), 422);
                        $info = stream_get_meta_data($local);
                        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($info['uri']);
                        if ($mime === 'application/zip') {
                            $zip = new \ZipArchive;
                            if ($zip->open($info['uri']) === true) {
                                if ($zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false) {
                                    $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                                }
                                $zip->close();
                            }
                        }
                        $extensions = ['application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
                        abort_unless(isset($extensions[$mime]), 422, 'Unsupported document content.');
                        $hash = hash_file('sha256', $info['uri']);
                        $revision = (string) Str::uuid();
                        $newPath = 'org_'.$org.'/documents/'.$revision.'.'.$extensions[$mime];
                        rewind($local);
                        abort_unless($disk->put($newPath, $local, ['visibility' => 'private']), 500);
                        $record->forceFill([
                            'storage_disk' => 's3', 'storage_path' => $newPath, 'mime_type' => $mime, 'size_bytes' => $bytes,
                            'original_filename' => preg_replace('/[\x00-\x1F]/', '', basename((string) ($data['original_filename'] ?? $source))), 'file_extension' => $extensions[$mime], 'sha256_checksum' => $hash,
                            'file_revision' => $revision, 'scan_state' => 'pending', 'virus_scanned' => false, 'virus_found' => false,
                            'virus_scanned_at' => null, 'ocr_required' => false, 'ocr_completed' => false,
                        ]);
                    } finally {
                        fclose($stream);
                        fclose($local);
                    }
                }
                if (! $existing) {
                    $record->owner_id = $actor->id;
                    $record->uploaded_by = $actor->id;
                    $record->status = 'draft';
                }
                $record->save();
                if ($newPath) {
                    DB::table('domain_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'event_type' => 'file.uploaded', 'payload' => json_encode(['document_uuid' => $record->uuid, 'revision' => $record->file_revision], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                    $version = (int) $record->versions()->max('version_number') + 1;
                    DocumentVersion::create(['document_id' => $record->id, 'version_number' => $version, 'storage_path' => $record->storage_path, 'size_bytes' => $record->size_bytes, 'sha256_checksum' => $record->sha256_checksum, 'created_by' => $actor->id]);
                }
                app(ArchiveAudit::class)->append($org, $actor->id, $existing ? 'DOCUMENT_UPDATED' : 'DOCUMENT_CREATED', Document::class, (string) $record->id, ['revision' => $record->file_revision, 'sha256' => $record->sha256_checksum]);

                return $record;
            });
        } catch (\Throwable $e) {
            if ($newPath !== null && ! Document::withoutGlobalScopes()->where('storage_path', $newPath)->exists()) {
                Storage::disk('s3')->delete($newPath);
            }
            throw $e;
        }
    }
}
