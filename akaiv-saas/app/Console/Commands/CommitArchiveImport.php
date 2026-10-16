<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ImportCommit;
use App\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CommitArchiveImport extends Command
{
    protected $signature = 'archive:commit-import {batch} {actor} {root} {manifest : JSON mapping source paths to manually approved metadata}';

    protected $description = 'Commit staged files idempotently into draft records; publication still requires separate review';

    public function handle(): int
    {
        $batch = DB::table('import_batches')->where('id', $this->argument('batch'))->first();
        abort_unless($batch, 404);
        $actor = User::findOrFail($this->argument('actor'));
        auth()->setUser($actor);
        abort_unless($actor->checkPermissionTo('archive.import') && $actor->organizations()->where('organizations.id', $batch->organization_id)->wherePivotIn('role', ['owner', 'workspace_manager', 'member_write'])->exists(), 403);
        $manifest = json_decode(file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
        $failures = 0;
        app(TenantContext::class)->run($batch->organization_id, function () use ($batch, $actor, $manifest, &$failures) {
            foreach (DB::table('import_items')->where('import_batch_id', $batch->id)->orderBy('id')->get() as $item) {
                try {
                    app(ImportCommit::class)->item($item->id, $actor, $this->argument('root'), $manifest[$item->source_path] ?? []);
                } catch (\Throwable $e) {
                    $failures++;
                    DB::table('import_items')->where('id', $item->id)->update(['error' => class_basename($e), 'updated_at' => now()]);
                    $this->error('Item '.$item->id.' requires correction.');
                }
            }
            DB::table('import_batches')->where('id', $batch->id)->update(['status' => $failures ? 'needs_attention' : 'catalogued', 'updated_at' => now()]);
        });

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
