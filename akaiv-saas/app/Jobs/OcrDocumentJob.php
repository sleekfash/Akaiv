<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentProcessingVersion;
use App\Services\DocumentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class OcrDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public DocumentProcessingVersion $version;

    public function __construct(public Document $document)
    {
        $this->version = new DocumentProcessingVersion($document);
    }

    public function handle(DocumentTextExtractor $extractor): void
    {
        $document = $this->version->query()->first();
        if (! $document || ! DocumentProcessingVersion::clean($document)) {
            return;
        }
        if (! $document->ocr_required || $document->ocr_completed) {
            IndexDocumentJob::dispatch($document)->afterCommit();

            return;
        }
        $input = null;
        try {
            $input = $this->version->input();
            $result = $extractor->extract($input, (string) $document->file_extension);
            $document = $this->version->apply(function (Document $document) use ($result) {
                if (! DocumentProcessingVersion::clean($document) || $document->ocr_completed) {
                    return false;
                }
                $document->updateQuietly([
                    'extracted_text' => $result['text'], 'page_count' => $result['page_count'], 'ocr_completed' => true,
                    'metadata' => array_merge($document->metadata ?? [], ['extraction' => ['status' => 'completed']]),
                ]);
            });
        } catch (Throwable $exception) {
            $this->version->apply(function (Document $document) {
                if (! DocumentProcessingVersion::clean($document) || $document->ocr_completed) {
                    return false;
                }
                $document->updateQuietly(['ocr_completed' => false,
                    'metadata' => array_merge($document->metadata ?? [], ['extraction' => ['status' => 'failed']])]);
            });
            throw $exception;
        } finally {
            if ($input !== null) {
                @unlink($input);
            }
        }
        if ($document) {
            IndexDocumentJob::dispatch($document)->afterCommit();
        }
    }
}
