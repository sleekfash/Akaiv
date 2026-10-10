<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class OcrDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public Document $document) {}

    public function handle(DocumentTextExtractor $extractor): void
    {
        $document = Document::where('organization_id', $this->document->organization_id)
            ->whereKey($this->document->id)->first();
        if (! $document || ! $this->canExtract($document)) {
            return;
        }
        if (! $document->ocr_required || $document->ocr_completed) {
            IndexDocumentJob::dispatch($document);

            return;
        }

        $input = tempnam(sys_get_temp_dir(), 'akaiv-ocr-');
        if ($input === false) {
            throw new RuntimeException('Unable to allocate extraction input.');
        }
        try {
            $sourcePath = $document->storage_path;
            $sourceChecksum = $document->sha256_checksum;
            $source = Storage::disk($document->storage_disk)->readStream($document->storage_path);
            if (! is_resource($source)) {
                throw new RuntimeException('Document content is unavailable.');
            }
            try {
                $output = fopen($input, 'wb');
                if (! is_resource($output)) {
                    throw new RuntimeException('Unable to write extraction input.');
                }
                try {
                    if (stream_copy_to_stream($source, $output) === false) {
                        throw new RuntimeException('Unable to copy extraction input.');
                    }
                } finally {
                    fclose($output);
                }
            } finally {
                fclose($source);
            }

            $result = $extractor->extract($input, (string) $document->file_extension);
            $document->refresh();
            if (! $this->canExtract($document) || $document->storage_path !== $sourcePath
                || $document->sha256_checksum !== $sourceChecksum) {
                return;
            }
            $document->updateQuietly([
                'extracted_text' => $result['text'],
                'page_count' => $result['page_count'],
                'ocr_completed' => true,
                'metadata' => array_merge($document->metadata ?? [], ['extraction' => ['status' => 'completed']]),
            ]);
            IndexDocumentJob::dispatch($document);
        } catch (Throwable $e) {
            $document->refresh();
            if ($document->storage_path === $sourcePath && $document->sha256_checksum === $sourceChecksum) {
                $document->updateQuietly([
                    'ocr_completed' => false,
                    'metadata' => array_merge($document->metadata ?? [], ['extraction' => ['status' => 'failed']]),
                ]);
            }
            throw $e;
        } finally {
            @unlink($input);
        }
    }

    private function canExtract(Document $document): bool
    {
        return $document->virus_scanned && ! $document->virus_found && ! $document->trashed()
            && in_array($document->status, ['published', 'draft', 'archived'], true);
    }
}
