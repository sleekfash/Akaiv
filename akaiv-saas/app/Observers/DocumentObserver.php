<?php

namespace App\Observers;

use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;

class DocumentObserver
{
    public function saved(Document $document): void
    {
        if ($document->wasRecentlyCreated || $document->wasChanged('file_revision')) {
            VirusScanDocumentJob::dispatch($document)->afterCommit();
        }
    }
}
