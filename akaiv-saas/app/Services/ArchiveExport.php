<?php

namespace App\Services;

use App\Models\CaseFile;
use App\Models\CaseProceeding;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ArchiveExport
{
    public function create(User $actor, string $output): void
    {
        $org = app(TenantContext::class)->id($actor);
        abort_unless($org && auth()->id() === $actor->id && app(TenantContext::class)->member($actor, $org) && $actor->checkPermissionTo('archive.export'), 403);
        abort_if(file_exists($output), 409, 'Refusing to overwrite an existing export');
        $zip = new ZipArchive;
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Cannot create export');
        }
        $temporary = [];
        try {
            $manifest = DB::transaction(function () use ($actor, $org, $zip, &$temporary) {
                $cases = CaseFile::orderBy('id')->get();
                $proceedings = CaseProceeding::orderBy('id')->get();
                $files = [];
                foreach (Document::orderBy('id')->lockForUpdate()->get() as $document) {
                    if (! app(ArchiveAccess::class)->document($actor, $document) || ! app(ArchiveAccess::class)->eligible($document)) {
                        continue;
                    }
                    $source = Storage::disk($document->storage_disk)->readStream($document->storage_path);
                    if (! is_resource($source)) {
                        throw new \RuntimeException('Missing export source');
                    }
                    $file = tmpfile();
                    if (! is_resource($file)) {
                        throw new \RuntimeException('Cannot snapshot file');
                    }
                    $temporary[] = $file;
                    try {
                        stream_copy_to_stream($source, $file);
                    } finally {
                        fclose($source);
                    }
                    $path = stream_get_meta_data($file)['uri'];
                    if (! hash_equals($document->sha256_checksum, hash_file('sha256', $path))) {
                        throw new \RuntimeException('Export checksum mismatch');
                    }
                    $key = 'files/'.$document->uuid;
                    $zip->addFile($path, $key);
                    $files[] = ['entry' => $key, 'sha256' => $document->sha256_checksum, 'bytes' => $document->size_bytes, 'metadata' => $document->getAttributes(), 'versions' => $document->versions()->where('storage_path', $document->storage_path)->get()->map->getAttributes()->all()];
                }
                $checkpoint = app(ArchiveAudit::class)->append($org, $actor->id, 'ARCHIVE_EXPORT_CREATED', 'organization', (string) $org, ['files' => count($files)]);

                return ['format' => 'akaiv-export-v1', 'organization_id' => $org, 'cases' => $cases->map->getAttributes()->all(), 'proceedings' => $proceedings->map->getAttributes()->all(), 'files' => $files, 'audit_checkpoint' => $checkpoint, 'created_at' => now()->toIso8601String()];
            });
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            if (! $zip->close()) {
                throw new \RuntimeException('Could not finalize export');
            }
            $this->verify($output);
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($output);
            throw $e;
        } finally {
            foreach ($temporary as $file) {
                fclose($file);
            }
        }
    }

    public function verify(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot read export');
        }
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? null) !== 'akaiv-export-v1') {
                throw new \RuntimeException('Unknown export format');
            }
            $cases = array_column($manifest['cases'], 'id');
            $proceedings = array_column($manifest['proceedings'], 'id');
            foreach ($manifest['proceedings'] as $proceeding) {
                if (! in_array($proceeding['case_id'], $cases)) {
                    throw new \RuntimeException('Broken proceeding relationship');
                }
            }
            foreach ($manifest['files'] as $file) {
                $source = $zip->getStream($file['entry']);
                if (! is_resource($source)) {
                    throw new \RuntimeException('Missing exported file');
                }
                $context = hash_init('sha256');
                $bytes = hash_update_stream($context, $source);
                fclose($source);
                if (! hash_equals($file['sha256'], hash_final($context)) || $bytes !== (int) $file['bytes']) {
                    throw new \RuntimeException('Exported file verification failed');
                }
                $meta = $file['metadata'];
                if (($meta['case_id'] && ! in_array($meta['case_id'], $cases)) || ($meta['proceeding_id'] && ! in_array($meta['proceeding_id'], $proceedings))) {
                    throw new \RuntimeException('Broken file relationship');
                }
            }

            return ['files' => count($manifest['files']), 'cases' => count($cases), 'proceedings' => count($proceedings), 'checkpoint' => $manifest['audit_checkpoint']];
        } finally {
            $zip->close();
        }
    }
}
