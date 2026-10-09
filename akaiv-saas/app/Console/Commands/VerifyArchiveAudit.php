<?php

namespace App\Console\Commands;

use App\Services\ArchiveAudit;
use Illuminate\Console\Command;

class VerifyArchiveAudit extends Command
{
    protected $signature = 'archive:audit-verify {--checkpoint= : Independently retained JSON checkpoint}';

    protected $description = 'Verify the complete audit chain against its head and optional external checkpoint';

    public function handle(): int
    {
        try {
            $checkpoint = $this->option('checkpoint') ? json_decode(file_get_contents($this->option('checkpoint')), true, 512, JSON_THROW_ON_ERROR) : null;
            $this->line(json_encode(app(ArchiveAudit::class)->verify($checkpoint), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
