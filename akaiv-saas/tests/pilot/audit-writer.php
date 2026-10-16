<?php

use App\Services\ArchiveAudit;

require __DIR__.'/bootstrap.php';

for ($i = 0; $i < 10; $i++) {
    app(ArchiveAudit::class)->append(0, null, 'PILOT_CONCURRENT_APPEND', 'synthetic', (string) $i);
}
