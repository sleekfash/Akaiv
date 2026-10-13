<?php

require __DIR__.'/bootstrap.php';

use App\Models\Document;
use App\Services\ArchiveAudit;
use Illuminate\Support\Facades\DB;

$path = getenv('PILOT_RECOVERY_MANIFEST');
$tables = ['users', 'organizations', 'organization_user', 'cases', 'case_access_grants', 'case_proceedings', 'documents', 'document_versions', 'archive_audit_events', 'domain_events', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];
$counts = [];
$fingerprints = [];
foreach ($tables as $table) {
    $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
    sort($rows);
    $counts[$table] = count($rows);
    $fingerprints[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}
if (($argv[1] ?? '') === 'create') {
    file_put_contents($path, json_encode(['counts' => $counts, 'fingerprints' => $fingerprints, 'checkpoint' => app(ArchiveAudit::class)->verify()], JSON_THROW_ON_ERROR));
    echo "Independent synthetic recovery manifest created.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($counts !== $manifest['counts'] || $fingerprints !== $manifest['fingerprints']) {
        throw new RuntimeException('Restored record counts differ');
    }
    app(ArchiveAudit::class)->verify($manifest['checkpoint']);
    foreach (Document::withoutGlobalScopes()->get() as $doc) {
        $file = getenv('RESTORE_OBJECT_DIR').'/'.$doc->storage_path;
        if (! is_file($file) || ! hash_equals($doc->sha256_checksum, hash_file('sha256', $file)) || filesize($file) !== (int) $doc->size_bytes) {
            throw new RuntimeException('Restored immutable object mismatch');
        }
    }
    foreach (DB::table('document_versions')->get() as $version) {
        $file = getenv('RESTORE_OBJECT_DIR').'/'.$version->storage_path;
        if (! is_file($file) || ! hash_equals($version->sha256_checksum, hash_file('sha256', $file))) {
            throw new RuntimeException('Restored historical revision mismatch');
        }
    }
    echo "Isolated PostgreSQL/object restore passed: record counts, immutable bytes and independent audit checkpoint.\n";
} else {
    throw new RuntimeException('Specify create or verify');
}
