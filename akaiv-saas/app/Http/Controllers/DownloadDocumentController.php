<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\PrivateFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDocumentController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return app(PrivateFileResponse::class)->serve($document, 'attachment');
    }
}
