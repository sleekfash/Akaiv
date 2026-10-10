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
        $fresh = Document::where('organization_id', $this->document->organization_id)
            ->whereKey($this->document->id)->first();

        if (! $fresh || ! $fresh->shouldBeSearchable()) {
            $this->document->unsearchable();
            return;
        }

        $fresh->searchable();
    }
}
