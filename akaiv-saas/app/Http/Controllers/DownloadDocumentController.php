<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDocumentController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        abort_if(
            ! $document->isContentAccessible(),
            404,
        );

        $disk = Storage::disk($document->storage_disk);
        abort_unless($disk->exists($document->storage_path), 404);

        $stream = $disk->readStream($document->storage_path);
        abort_unless(is_resource($stream), 404);

        $document->incrementDownloadCount();

        activity()
            ->on($document)
            ->withProperties(['ip' => request()->ip()])
            ->log('document.downloaded');

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($document->original_filename, '"\\').'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
