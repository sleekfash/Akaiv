<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\PrivateFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewDocumentController extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return app(PrivateFileResponse::class)->serve($document, 'inline');
    }
}
