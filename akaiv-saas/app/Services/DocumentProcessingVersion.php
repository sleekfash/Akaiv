<?php

namespace App\Services;

use App\Models\Document;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentProcessingVersion
{
    public array $identity;

    public function __construct(Document $document)
    {
        $this->identity = $document->only(['id', 'organization_id', 'storage_disk', 'storage_path', 'sha256_checksum']);
    }

    public function query(): Builder
    {
        return Document::withoutGlobalScopes()->where($this->identity)->whereNull('deleted_at');
    }

    public function apply(Closure $callback): ?Document
    {
        return DB::transaction(function () use ($callback) {
            $document = $this->query()->lockForUpdate()->first();
            if (! $document || $document->status === 'deleted') {
                return null;
            }

            return $callback($document) === false ? null : $document;
        });
    }

    public function input(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'akaiv-version-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate document input.');
        }
        try {
            $source = Storage::disk($this->identity['storage_disk'])->readStream($this->identity['storage_path']);
            if (! is_resource($source)) {
                throw new RuntimeException('Document content is unavailable.');
            }
            try {
                $output = fopen($path, 'wb');
                if (! is_resource($output)) {
                    throw new RuntimeException('Unable to write document input.');
                }
                try {
                    $size = stream_copy_to_stream($source, $output, 268435457);
                    if ($size === false || $size === 0 || $size > 268435456) {
                        throw new RuntimeException('Document exceeds the processing size limit or is empty.');
                    }
                } finally {
                    fclose($output);
                }
            } finally {
                fclose($source);
            }
            $expected = $this->identity['sha256_checksum'];
            if (! is_string($expected) || ! hash_equals($expected, hash_file('sha256', $path))) {
                throw new RuntimeException('Document content does not match its recorded version.');
            }

            return $path;
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    public static function clean(Document $document): bool
    {
        return $document->virus_scanned && ! $document->virus_found
            && in_array($document->status, ['published', 'draft', 'archived'], true);
    }
}
