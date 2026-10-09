<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\ArchiveAudit;
use App\Services\ClamScanner;
use App\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VirusScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public int $documentId;

    public int $organizationId;

    public ?string $revision;

    public function __construct(Document $document)
    {
        $this->documentId = $document->id;
        $this->organizationId = $document->organization_id;
        $this->revision = $document->file_revision;
    }

    public function handle(): void
    {
        app(TenantContext::class)->run($this->organizationId, function () {
            $document = Document::find($this->documentId);
            if (! $document || $document->file_revision !== $this->revision || $document->scan_state === 'clean') {
                return;
            }
            $source = null;
            $local = null;
            try {
                $source = Storage::disk($document->storage_disk)->readStream($document->storage_path);
                if (! is_resource($source)) {
                    throw new \RuntimeException('Missing file');
                }
                $local = tmpfile();
                if (! is_resource($local)) {
                    throw new \RuntimeException('Cannot create scan snapshot');
                }
                stream_copy_to_stream($source, $local);
                if (! $document->sha256_checksum || ! hash_equals($document->sha256_checksum, hash_file('sha256', stream_get_meta_data($local)['uri']))) {
                    throw new \RuntimeException('File checksum mismatch');
                }
                rewind($local);
                $clean = app(ClamScanner::class)->scan($local);
                $this->record($clean ? 'clean' : 'infected', $clean);
            } catch (\Throwable $e) {
                $this->record('error', false);
                throw $e;
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($local)) {
                    fclose($local);
                }
            }
        });
    }

    private function record(string $state, bool $clean): void
    {
        DB::transaction(function () use ($state) {
            $record = Document::whereKey($this->documentId)->lockForUpdate()->first();
            if (! $record || $record->file_revision !== $this->revision) {
                return;
            }
            $record->forceFill(['scan_state' => $state, 'virus_scanned' => $state !== 'error', 'virus_found' => $state === 'infected', 'virus_scanned_at' => now()])->saveQuietly();
            app(ArchiveAudit::class)->append($this->organizationId, null, 'FILE_SCAN_RESULT', Document::class, (string) $record->id, ['revision' => $this->revision, 'result' => $state]);
            // Phase 1 deliberately dispatches no OCR, text preprocessing, AI, or content indexer.
        });
    }
}
