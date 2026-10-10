<?php

namespace App\Jobs;

use App\Models\Document;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Socket\Raw\Factory;
use Xenolope\Quahog\Client;

class VirusScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        $path = $this->document->storage_path;
        $disk = Storage::disk($this->document->storage_disk);

        if (! $disk->exists($path)) {
            $this->document->updateQuietly([
                'status' => 'quarantined',
                'virus_scanned' => false,
            ]);

            return;
        }

        try {
            $stream = $disk->readStream($path);
            $socket = (new Factory)->createClient(sprintf(
                'tcp://%s:%d',
                config('services.clamav.host'),
                config('services.clamav.port', 3310),
            ));
            $clam = new Client($socket);
            $result = $clam->scanResourceStream($stream);
            $clam->disconnect();

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($result->isFound()) {
                $this->document->updateQuietly([
                    'status' => 'quarantined',
                    'virus_scanned' => true,
                    'virus_found' => true,
                    'virus_scanned_at' => now(),
                    'metadata' => array_merge($this->document->metadata ?? [], [
                        'virus_signature' => $result->getReason(),
                    ]),
                ]);
                activity()
                    ->on($this->document)
                    ->withProperties(['signature' => $result->getReason()])
                    ->log('document.virus_detected');

                return;
            }
        } catch (Exception $e) {
            report($e);
            $this->document->updateQuietly([
                'status' => 'quarantined',
            ]);
            $this->release(300);

            return;
        }

        $this->document->updateQuietly([
            'virus_scanned' => true,
            'virus_found' => false,
            'virus_scanned_at' => now(),
            'status' => $this->document->status === 'uploading' ? 'published' : $this->document->status,
        ]);

        OcrDocumentJob::dispatch($this->document);
        ThumbnailDocumentJob::dispatch($this->document)->delay(now()->addSeconds(5));
    }
}
