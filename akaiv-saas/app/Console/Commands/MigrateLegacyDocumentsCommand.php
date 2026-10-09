<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\ArchiveAudit;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyDocumentsCommand extends Command
{
    protected $signature = 'app:migrate-legacy-documents {--dry-run} {--legacy-files=} {--target-org-slug=default} {--actor=} {--manifest=}';

    protected $description = 'Read-only discovery or staged manual intake; never publishes or creates users';

    public function handle(): int
    {
        $org = Organization::where('slug', $this->option('target-org-slug'))->where('is_suspended', false)->firstOrFail();
        $root = realpath((string) $this->option('legacy-files'));
        if (! $root || ! is_dir($root)) {
            $this->error('A valid source directory is required.');

            return self::FAILURE;
        }
        $manifest = [];
        if ($this->option('manifest')) {
            $manifest = json_decode(file_get_contents($this->option('manifest')), true, 512, JSON_THROW_ON_ERROR);
        }
        $items = [];
        $failures = [];
        $seen = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $path = $file->getRealPath();
            if (! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = substr($path, strlen($root) + 1);
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, ['pdf', 'doc', 'docx', 'txt', 'csv'], true)) {
                continue;
            }
            if (! is_readable($path) || $file->getSize() < 1 || $file->getSize() > config('archive.max_upload_bytes')) {
                $failures[] = $relative;

                continue;
            }
            $sha = hash_file('sha256', $path);
            if (isset($seen[$sha])) {
                $this->line('Duplicate content: '.$relative.' (source retained)');

                continue;
            }
            $seen[$sha] = true;
            $items[] = ['source_path' => $relative, 'sha256' => $sha, 'bytes' => $file->getSize(), 'metadata' => $manifest[$relative] ?? null];
        }
        $this->line(json_encode(['files' => count($items), 'failures' => $failures, 'mode' => $this->option('dry-run') ? 'read_only' : 'staging'], JSON_THROW_ON_ERROR));
        if ($this->option('dry-run')) {
            return $failures ? self::FAILURE : self::SUCCESS;
        }
        $actor = User::findOrFail($this->option('actor'));
        if (! $actor->checkPermissionTo('archive.import') || ! $actor->organizations()->where('organizations.id', $org->id)->wherePivotIn('role', ['owner', 'workspace_manager', 'member_write'])->exists()) {
            $this->error('The explicit importing actor must have a writable membership and archive.import permission.');

            return self::FAILURE;
        }
        app(TenantContext::class)->run($org->id, function () use ($org, $actor, $items, $root) {
            DB::transaction(function () use ($org, $actor, $items, $root) {
                $batch = DB::table('import_batches')->insertGetId(['organization_id' => $org->id, 'created_by' => $actor->id, 'status' => 'staged', 'created_at' => now(), 'updated_at' => now()]);
                foreach ($items as $item) {
                    DB::table('import_items')->insert(['import_batch_id' => $batch, 'source_path' => $item['source_path'], 'sha256' => $item['sha256'], 'bytes' => $item['bytes'], 'metadata' => json_encode($item['metadata'], JSON_THROW_ON_ERROR), 'status' => 'awaiting_metadata', 'created_at' => now(), 'updated_at' => now()]);
                }
                app(ArchiveAudit::class)->append($org->id, $actor->id, 'IMPORT_STAGED', 'import_batch', (string) $batch, ['root' => $root, 'files' => count($items)]);
                $this->info('Staged batch '.$batch.'. Source files remain unchanged. No authoritative documents were published.');
            });
        });

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
