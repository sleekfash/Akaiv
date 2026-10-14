<?php

require __DIR__.'/bootstrap.php';

use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

$document = Document::withoutGlobalScopes()->where('friendly_name', 'Pilot error judgment')->firstOrFail();
if (($argv[1] ?? '') === 'dispatch') {
    VirusScanDocumentJob::dispatch($document);
} elseif (($argv[1] ?? '') === 'verify') {
    if (DB::table('failed_jobs')->count() !== 1 || $document->fresh()->scan_state !== 'error' || $document->fresh()->status !== 'draft') {
        throw new RuntimeException('Queue failure must be retained without publication');
    }
    echo "Redis worker failure retained in PostgreSQL; file remains an unpublished error-state draft.\n";
} else {
    throw new RuntimeException('Specify dispatch or verify');
}
