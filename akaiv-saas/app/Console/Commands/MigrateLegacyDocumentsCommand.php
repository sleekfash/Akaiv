<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentFormats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class MigrateLegacyDocumentsCommand extends Command
{
    protected $signature = 'app:migrate-legacy-documents
                            {--dry-run : Validate the complete plan without writes or jobs}
                            {--legacy-db= : Deprecated; configure legacy_mysql}
                            {--legacy-user= : Deprecated; configure legacy_mysql}
                            {--legacy-pass= : Deprecated; configure legacy_mysql}
                            {--legacy-files= : Read-only legacy documents snapshot directory}
                            {--mapping= : Private JSON mapping to existing users and folders}
                            {--target-org-slug=default : Target organization slug}';

    protected $description = 'Import reconciled legacy database records into private storage';

    public function handle(): int
    {
        try {
            foreach (['legacy-db', 'legacy-user', 'legacy-pass'] as $option) {
                if ($this->option($option) !== null) {
                    throw new InvalidArgumentException('Configure legacy_mysql with LEGACY_DB_* settings, not connection CLI options.');
                }
            }
            $root = realpath((string) $this->option('legacy-files'));
            if (! $this->option('legacy-files') || $root === false || ! is_dir($root)) {
                throw new InvalidArgumentException('A readable legacy snapshot directory is required.');
            }
            $disk = config('filesystems.default');
            if (! in_array($disk, ['s3', 'private'], true) || config("filesystems.disks.{$disk}.visibility") === 'public') {
                throw new InvalidArgumentException('Configure the s3 or private disk for imports.');
            }
            $org = Organization::where('slug', $this->option('target-org-slug'))->first();
            if (! $org || $org->is_suspended) {
                throw new InvalidArgumentException('Target organization must exist and be active.');
            }
            $map = $this->readMapping($org);
            $rows = DB::connection('legacy_mysql')->table('documents as d')
                ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
                ->leftJoin('folders as f', 'f.id', '=', 'd.folder_id')
                ->select('d.*', 'u.active as source_user_active', 'f.active as source_folder_active',
                    'f.user_id as source_folder_owner', 'f.name as source_folder_name')
                ->orderBy('d.id')->get();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Unable to read mapping, target schema, or legacy database. No filesystem-only fallback is permitted.');

            return self::FAILURE;
        }

        $plan = [];
        $failures = $excluded = $unchanged = 0;
        $names = [];
        foreach ($rows as $row) {
            try {
                $existing = DB::table('legacy_document_imports')->where('organization_id', $org->id)
                    ->where('source_system', $map['source_system'])->where('legacy_document_id', $row->id)->first();
                if ($row->status === 'Deleted' && ! $existing) {
                    $excluded++;

                    continue;
                }
                $item = $this->planDocument($row, $root, $map, $org);
                if ($existing) {
                    $document = Document::withTrashed()->where('organization_id', $org->id)->whereKey($existing->document_id)->first();
                    if (! $document || $document->trashed() || $document->status === 'deleted'
                        || $existing->source_fingerprint !== $item['fingerprint']
                        || $document->sha256_checksum !== $item['sha256']) {
                        throw new InvalidArgumentException('Imported source changed or target is unavailable; reconcile explicitly.');
                    }
                    $this->verifyObject($document->storage_disk, $document->storage_path, $item['sha256']);
                    $unchanged++;

                    continue;
                }
                $nameKey = json_encode([$item['folder_id'], $row->name], JSON_THROW_ON_ERROR);
                if (isset($names[$nameKey]) || $this->titleExists($org, $item)) {
                    throw new InvalidArgumentException('Target folder/title collision requires an explicit disposition.');
                }
                $names[$nameKey] = true;
                $plan[] = $item;
            } catch (InvalidArgumentException $e) {
                $failures++;
                $this->error("Legacy document {$row->id}: {$e->getMessage()}");
            } catch (Throwable) {
                $failures++;
                $this->error("Legacy document {$row->id}: validation failed; check restored schema and storage access.");
            }
        }
        $this->info(sprintf('Plan: %d new, %d unchanged, %d deleted excluded, %d unresolved.', count($plan), $unchanged, $excluded, $failures));
        if ($failures > 0) {
            $this->error('Preflight failed. No import writes were performed.');

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No users, folders, documents, files, or jobs were created.');

            return self::SUCCESS;
        }
        foreach ($plan as $item) {
            try {
                $this->importDocument($item, $root, $org, $map);
            } catch (Throwable) {
                $this->error("Legacy document {$item['row']->id}: import failed. Inspect storage and the ledger before retrying.");

                return self::FAILURE;
            }
        }
        $this->info(count($plan).' documents imported pending virus scanning.');

        return self::SUCCESS;
    }

    private function readMapping(Organization $org): array
    {
        $path = (string) $this->option('mapping');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('An approved private JSON mapping file is required.');
        }
        $map = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($map) || ($map['organization_slug'] ?? null) !== $org->slug
            || ! is_string($map['source_system'] ?? null) || ! preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $map['source_system'])
            || ! is_array($map['users'] ?? null) || ! is_array($map['folders'] ?? null)
            || ! in_array($map['source_timezone'] ?? null, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Mapping requires organization_slug, stable source_system, source_timezone, users and folders.');
        }

        return $map;
    }

    private function planDocument(object $row, string $root, array $map, Organization $org): array
    {
        if ($row->status !== 'Available' || (int) $row->source_user_active !== 1) {
            throw new InvalidArgumentException('Only Available records owned by active source users can be imported.');
        }
        $userId = $this->mappedId($map['users'], $row->user_id);
        $user = User::whereKey($userId)->first();
        if (! $user || ! $user->organizations()->where('organizations.id', $org->id)->exists()) {
            throw new InvalidArgumentException('Mapped user must already belong to the target organization.');
        }
        $folderId = null;
        if ($row->folder_id !== null) {
            if ((int) $row->source_folder_active !== 1 || (string) $row->source_folder_owner !== (string) $row->user_id
                || $row->source_folder_name !== $row->folder) {
                throw new InvalidArgumentException('Source folder is missing, inactive, has another owner, or disagrees with its stored path.');
            }
            $folderId = $this->mappedId($map['folders'], $row->folder_id);
            if (! Folder::where('organization_id', $org->id)->whereKey($folderId)->exists()) {
                throw new InvalidArgumentException('Mapped folder must belong to the target organization.');
            }
        } elseif ($row->folder !== null && $row->folder !== '') {
            throw new InvalidArgumentException('Folderless record has a stored folder path; reconcile first.');
        }
        $segments = [$row->created_by];
        if ($row->folder !== null && $row->folder !== '') {
            $segments[] = $row->folder;
        }
        $segments[] = $row->file;
        $path = $root;
        foreach ($segments as $segment) {
            if (! is_string($segment) || $segment === '' || in_array(strtolower($segment), ['.', '..', 'trash', 'recycled'], true)
                || str_contains($segment, '/') || str_contains($segment, '\\') || str_contains($segment, "\0")
                || (PHP_OS_FAMILY === 'Windows' && str_contains($segment, ':'))) {
                throw new InvalidArgumentException('Unsafe or excluded source path component.');
            }
            $path .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($path)) {
                throw new InvalidArgumentException('Source symlinks are not accepted.');
            }
        }
        $resolved = realpath($path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        if ($resolved === false || ! str_starts_with(str_replace('\\', '/', $resolved), $prefix)
            || ! is_file($resolved) || ! is_readable($resolved) || filesize($resolved) === 0) {
            throw new InvalidArgumentException('Source file is missing, empty, unreadable, or outside the snapshot.');
        }
        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $mime = DocumentFormats::validate($resolved, $extension);
        if (! is_string($row->name) || trim($row->name) === '' || mb_strlen($row->name) > 500
            || mb_strlen((string) $row->folio_number) > 200) {
            throw new InvalidArgumentException('Title or folio does not fit the target schema.');
        }
        $created = $this->sourceDate($row->created_at, $map['source_timezone']);
        $updated = $this->sourceDate($row->updated_at, $map['source_timezone']);
        if ($updated->lessThan($created)) {
            throw new InvalidArgumentException('Source modification date precedes creation date.');
        }
        $sha = hash_file('sha256', $resolved);

        return ['row' => $row, 'path' => $resolved, 'user_id' => $userId, 'folder_id' => $folderId,
            'extension' => $extension, 'mime' => $mime, 'sha256' => $sha, 'size' => filesize($resolved),
            'fingerprint' => hash('sha256', json_encode([$row, $userId, $folderId, $sha, $map['source_timezone']], JSON_THROW_ON_ERROR)),
            'created_at' => $created, 'updated_at' => $updated];
    }

    private function mappedId(array $map, mixed $sourceId): int
    {
        $id = $map[(string) $sourceId] ?? null;
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Explicit source-to-target ID mapping is missing or invalid.');
        }

        return (int) $id;
    }

    private function sourceDate(?string $value, string $timezone): CarbonImmutable
    {
        if (! $value || ! preg_match('/\A[1-9]\d{3}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value)) {
            throw new InvalidArgumentException('Valid database timestamps are required; file dates are not substituted.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        if (! $date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Invalid source database timestamp.');
        }

        return $date->setTimezone(config('app.timezone'));
    }

    private function titleExists(Organization $org, array $item): bool
    {
        return Document::withTrashed()->where('organization_id', $org->id)->where('folder_id', $item['folder_id'])
            ->where('friendly_name', $item['row']->name)->exists();
    }

    private function verifyObject(string $disk, string $key, string $sha): void
    {
        $stream = Storage::disk($disk)->readStream($key);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Stored object is missing or unreadable.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if (! hash_equals($sha, hash_final($hash))) {
                throw new InvalidArgumentException('Stored object checksum does not match the source.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function importDocument(array $item, string $root, Organization $org, array $map): void
    {
        $uuid = (string) Str::uuid();
        $key = "org_{$org->id}/docs/{$uuid}/{$uuid}.{$item['extension']}";
        $diskName = config('filesystems.default');
        $disk = Storage::disk($diskName);
        $stream = fopen($item['path'], 'rb');
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('Source became unreadable.');
        }
        try {
            try {
                if (! $disk->put($key, $stream, ['visibility' => 'private'])) {
                    throw new InvalidArgumentException('Private storage write failed.');
                }
            } finally {
                fclose($stream);
            }
            $this->verifyObject($diskName, $key, $item['sha256']);
            Document::withoutSyncingToSearch(function () use ($item, $root, $org, $map, $uuid, $key, $diskName): void {
                DB::transaction(function () use ($item, $root, $org, $map, $uuid, $key, $diskName): void {
                    // Serialize imports to this organization, including title collision checks.
                    $lockedOrg = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
                    $fresh = $this->planDocument($item['row'], $root, $map, $lockedOrg);
                    if ($lockedOrg->is_suspended || $fresh['fingerprint'] !== $item['fingerprint'] || $this->titleExists($org, $item)) {
                        throw new InvalidArgumentException('Source or target changed after preflight.');
                    }
                    $document = new Document([
                        'uuid' => $uuid, 'organization_id' => $org->id, 'folder_id' => $item['folder_id'],
                        'owner_id' => $item['user_id'], 'uploaded_by' => $item['user_id'],
                        'friendly_name' => $item['row']->name, 'original_filename' => $item['row']->file,
                        'slug' => Str::limit(Str::slug($item['row']->name), 110, '').'-'.$uuid,
                        'storage_disk' => $diskName, 'storage_path' => $key, 'size_bytes' => $item['size'],
                        'mime_type' => $item['mime'], 'file_extension' => $item['extension'], 'sha256_checksum' => $item['sha256'],
                        'folio_number' => $item['row']->folio_number, 'description' => $item['row']->description,
                        'status' => 'uploading', 'virus_scanned' => false,
                        'metadata' => ['legacy' => ['source_system' => $map['source_system'], 'document_id' => $item['row']->id,
                            'user_id' => $item['row']->user_id, 'folder_id' => $item['row']->folder_id,
                            'created_at' => $item['row']->created_at, 'updated_at' => $item['row']->updated_at,
                            'source_timezone' => $map['source_timezone']]],
                    ]);
                    $document->created_at = $item['created_at'];
                    $document->updated_at = $item['updated_at'];
                    $document->save();
                    DB::table('legacy_document_imports')->insert([
                        'organization_id' => $org->id, 'source_system' => $map['source_system'],
                        'legacy_document_id' => $item['row']->id, 'document_id' => $document->id,
                        'source_fingerprint' => $item['fingerprint'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            });
        } catch (Throwable $e) {
            // Post-commit dispatch failure must not delete a committed document's object.
            if (! Document::withTrashed()->where('organization_id', $org->id)->where('uuid', $uuid)->exists()) {
                $disk->delete($key);
            }
            throw $e;
        }
    }
}
