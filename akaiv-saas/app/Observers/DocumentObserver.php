<?php

namespace App\Observers;

use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Document $document): void
    {
        VirusScanDocumentJob::dispatch($document);
    }
}
