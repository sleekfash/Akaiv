<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use thiagoalessio\TesseractOCR\TesseractOCR;

class OcrDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        if (! config('archive.ocr_enabled')) {
            return;
        }
        if (! $this->document->ocr_required || $this->document->ocr_completed) {
            return;
        }

        $disk = Storage::disk($this->document->storage_disk);
        if (! $disk->exists($this->document->storage_path)) {
            return;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'akaiv-ocr-');
        $ocrPath = $inputPath;

        try {
            file_put_contents($inputPath, $disk->get($this->document->storage_path));

            if (strtolower((string) $this->document->file_extension) === 'pdf') {
                $outputPrefix = $inputPath.'-page';
                $process = new Process([
                    'pdftoppm', '-f', '1', '-l', '1', '-png', '-singlefile',
                    $inputPath, $outputPrefix,
                ]);
                $process->mustRun();
                $ocrPath = $outputPrefix.'.png';
            }

            $text = (new TesseractOCR($ocrPath))
                ->lang('eng')
                ->run();

            $this->document->updateQuietly([
                'extracted_text' => trim($text),
                'ocr_completed' => true,
            ]);
        } finally {
            @unlink($inputPath);
            if ($ocrPath !== $inputPath) {
                @unlink($ocrPath);
            }
        }
    }
}
