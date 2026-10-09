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

class ThumbnailDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        if (strtolower((string) $this->document->file_extension) !== 'pdf') {
            return;
        }

        $disk = Storage::disk($this->document->storage_disk);
        if (! $disk->exists($this->document->storage_path)) {
            return;
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'akaiv-thumb-');
        $outputPath = $inputPath.'.png';

        try {
            file_put_contents($inputPath, $disk->get($this->document->storage_path));

            $process = new Process([
                'pdftoppm', '-f', '1', '-l', '1', '-png', '-singlefile', '-scale-to', '1200',
                $inputPath, $inputPath,
            ]);
            $process->mustRun();

            $thumbnailPath = 'thumbnails/'.$this->document->uuid.'.png';
            Storage::disk('private')->put($thumbnailPath, file_get_contents($outputPath));
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }
}
