<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateFileResponse
{
    public function serve(Document $document, string $disposition): StreamedResponse
    {
        abort_unless(app(ArchiveAccess::class)->eligible($document), 404);
        $source = Storage::disk($document->storage_disk)->readStream($document->storage_path);
        abort_unless(is_resource($source), 404);
        $file = tmpfile();
        abort_unless(is_resource($file), 500);
        try {
            stream_copy_to_stream($source, $file);
            $path = stream_get_meta_data($file)['uri'];
            abort_unless(hash_equals($document->sha256_checksum, hash_file('sha256', $path)), 409, 'File integrity mismatch');
            DB::table('documents')->where('id', $document->id)->increment($disposition === 'attachment' ? 'download_count' : 'view_count', 1, ['last_accessed_at' => now()]);
            app(ArchiveAudit::class)->append($document->organization_id, auth()->id(), $disposition === 'attachment' ? 'FILE_DOWNLOAD_STARTED' : 'FILE_PREVIEW_STARTED', Document::class, (string) $document->id, ['revision' => $document->file_revision]);
        } catch (\Throwable $e) {
            fclose($file);
            throw $e;
        } finally {
            fclose($source);
        }
        rewind($file);

        return response()->stream(function () use ($file) {
            try {
                fpassthru($file);
            } finally {
                fclose($file);
            }
        }, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $disposition.'; filename="'.addcslashes(basename($document->original_filename), '"\\').'"',
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
