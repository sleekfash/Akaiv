<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentProcessingVersion;
use App\Services\DocumentThumbnailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ThumbnailDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public DocumentProcessingVersion $version;

    public function __construct(public Document $document)
    {
        $this->version = new DocumentProcessingVersion($document);
    }

    public function handle(): void
    {
        $document = $this->version->query()->first();
        if (! $document || ! DocumentProcessingVersion::clean($document) || strtolower((string) $document->file_extension) !== 'pdf') {
            return;
        }
        $input = $this->version->input();
        $output = $input.'.png';
        $thumbnail = 'thumbnails/'.$document->uuid.'/'.Str::uuid().'.png';
        $disk = Storage::disk($document->storage_disk);
        $committed = false;
        try {
            app(DocumentThumbnailer::class)->render($input);
            if (! $disk->put($thumbnail, file_get_contents($output), ['visibility' => 'private'])) {
                throw new RuntimeException('Unable to store thumbnail.');
            }
            $committed = $this->version->apply(function (Document $document) use ($thumbnail) {
                if (! DocumentProcessingVersion::clean($document)) {
                    return false;
                }
                $document->updateQuietly(['metadata' => array_merge($document->metadata ?? [], ['thumbnail_path' => $thumbnail])]);
            }) !== null;
        } finally {
            @unlink($input);
            @unlink($output);
            if (! $committed) {
                $disk->delete($thumbnail);
            }
        }
    }
}
