<?php

use App\Models\Document;

require __DIR__.'/bootstrap.php';

$record = Document::withoutGlobalScopes()->where('friendly_name', 'Browser uploaded judgment')->first();
echo json_encode(['scan_state' => $record?->scan_state, 'status' => $record?->status], JSON_THROW_ON_ERROR);
