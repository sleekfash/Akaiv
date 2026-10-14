<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public Document $document) {}

    public function handle(): void
    {
        if ($this->document->status === 'deleted') {
            $this->document->unsearchable();

            return;
        }

        $this->document->unsearchable(); // Phase 1 searches authorized metadata in PostgreSQL, not document content.
    }
}
