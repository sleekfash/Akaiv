<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ArchiveExport;
use App\Services\TenantContext;
use Illuminate\Console\Command;

class ExportArchive extends Command
{
    protected $signature = 'archive:export {organization} {actor} {output}';

    protected $description = 'Export authorized published files and portable judicial metadata; sealed grants still apply';

    public function handle(): int
    {
        $actor = User::findOrFail($this->argument('actor'));
        auth()->setUser($actor);
        app(TenantContext::class)->run((int) $this->argument('organization'), fn () => app(ArchiveExport::class)->create($actor, $this->argument('output')));
        $this->line(json_encode(app(ArchiveExport::class)->verify($this->argument('output')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
