<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentAntivirus;
use App\Services\DocumentProcessingVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class VirusScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $backoff = 300;

    public DocumentProcessingVersion $version;

    public function __construct(public Document $document)
    {
        $this->version = new DocumentProcessingVersion($document);
    }

    public function handle(): void
    {
        $current = $this->version->query()->first();
        if (! $current || $current->status === 'deleted' || $current->virus_found) {
            return;
        }
        if ($current->virus_scanned) {
            if (DocumentProcessingVersion::clean($current)) {
                OcrDocumentJob::dispatch($current)->afterCommit();
                ThumbnailDocumentJob::dispatch($current)->delay(now()->addSeconds(5))->afterCommit();
            }

            return;
        }
        if (! Storage::disk($current->storage_disk)->exists($current->storage_path)) {
            $this->version->apply(function (Document $document) {
                if (! $document->virus_scanned) {
                    $document->updateQuietly(['status' => 'quarantined', 'virus_scanned' => false]);
                }
            });

            return;
        }
        $input = null;
        try {
            $input = $this->version->input();
            $result = app(DocumentAntivirus::class)->scan($input);
            if (! in_array($result['verdict'] ?? null, ['clean', 'infected'], true)) {
                throw new RuntimeException('Antivirus did not return a valid verdict.');
            }
            $document = $this->version->apply(function (Document $document) use ($result) {
                if ($document->virus_scanned) {
                    return false;
                }
                $infected = $result['verdict'] === 'infected';
                $metadata = $document->metadata ?? [];
                $target = $metadata['pending_status'] ?? ($document->status === 'quarantined' ? 'draft' : $document->status);
                unset($metadata['virus_signature'], $metadata['scan_error']);
                if ($infected) {
                    $metadata['virus_signature'] = $result['reason'] ?? 'Detected';
                }
                $document->updateQuietly([
                    'virus_scanned' => true, 'virus_found' => $infected, 'virus_scanned_at' => now(),
                    'status' => $infected ? 'quarantined' : ($target === 'uploading' ? 'published' : $target),
                    'metadata' => $metadata,
                ]);
            });
        } catch (Throwable $exception) {
            $this->version->apply(function (Document $document) {
                if ($document->virus_scanned) {
                    return false;
                }
                $metadata = $document->metadata ?? [];
                $metadata['pending_status'] ??= $document->status === 'uploading' ? 'published' : $document->status;
                $metadata['scan_error'] = true;
                $document->updateQuietly(['status' => 'quarantined', 'virus_scanned' => false,
                    'virus_found' => false, 'virus_scanned_at' => null, 'metadata' => $metadata]);
            });
            throw $exception;
        } finally {
            if ($input !== null) {
                @unlink($input);
            }
        }
        if ($document && $document->virus_found) {
            activity()->on($document)->withProperties([
                'signature' => $document->metadata['virus_signature'] ?? 'Detected',
                'sha256_checksum' => $document->sha256_checksum,
            ])->log('document.virus_detected');
        }
        if ($document && ! $document->virus_found) {
            OcrDocumentJob::dispatch($document)->afterCommit();
            ThumbnailDocumentJob::dispatch($document)->delay(now()->addSeconds(5))->afterCommit();
        }
    }
}
