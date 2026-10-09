<?php

namespace App\Console\Commands;

use App\Services\ArchiveExport;
use Illuminate\Console\Command;

class VerifyExport extends Command
{
    protected $signature = 'archive:verify-export {path}';

    protected $description = 'Verify portable file hashes, sizes and judicial relationships without writing data';

    public function handle(): int
    {
        try {
            $this->line(json_encode(app(ArchiveExport::class)->verify($this->argument('path')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
