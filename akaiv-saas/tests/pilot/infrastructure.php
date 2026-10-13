<?php

require __DIR__.'/bootstrap.php';

use App\Jobs\VirusScanDocumentJob;
use App\Models\CaseFile;
use App\Models\User;
use App\Services\ArchiveAudit;
use App\Services\ArchiveWorkflow;
use App\Services\FileIngestion;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

function pilotAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
pilotAssert(DB::getDriverName() === 'pgsql', 'Real PostgreSQL required');
pilotAssert(config('filesystems.disks.s3.endpoint') === 'http://127.0.0.1:9000', 'Isolated local S3 endpoint required');
Queue::fake();
$client = new S3Client(['version' => 'latest', 'region' => config('filesystems.disks.s3.region'), 'endpoint' => config('filesystems.disks.s3.endpoint'), 'use_path_style_endpoint' => true, 'credentials' => ['key' => config('filesystems.disks.s3.key'), 'secret' => config('filesystems.disks.s3.secret')]]);
$client->createBucket(['Bucket' => config('filesystems.disks.s3.bucket')]);
$clerk = User::where('email', 'clerk@example.test')->firstOrFail();
$judge = User::where('email', 'judge@example.test')->firstOrFail();
auth()->login($clerk);
session(['active_organization_id' => $clerk->organizations()->first()->id]);
$case = CaseFile::where('title', 'Synthetic Pilot Case')->firstOrFail();
$disk = Storage::disk('s3');
$clean = null;
foreach (['clean' => 'Synthetic private pilot judgment', 'infected' => 'X5O!P%@AP[4'.'\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*', 'error' => 'Synthetic scanner outage document'] as $state => $bytes) {
    $incoming = 'org_'.$case->organization_id.'/incoming/user_'.$clerk->id.'/'.$state.'.txt';
    pilotAssert($disk->put($incoming, $bytes, ['visibility' => 'private']), 'S3 incoming upload failed');
    $doc = app(FileIngestion::class)->save(['storage_path' => $incoming, 'case_id' => $case->id, 'friendly_name' => 'Pilot '.$state.' judgment', 'judicial_document_type' => 'judgment', 'date_delivered' => '2026-10-13'], $clerk);
    pilotAssert($doc->sha256_checksum === hash('sha256', $bytes), 'Ingested checksum mismatch');
    pilotAssert($disk->get($doc->storage_path) === $bytes, 'S3 immutable bytes mismatch');
    $url = 'http://127.0.0.1:9000/'.config('filesystems.disks.s3.bucket').'/'.$doc->storage_path;
    $response = Http::get($url);
    pilotAssert($response->status() === 403, 'Anonymous object access must be denied');
    if ($state === 'error') {
        config(['services.clamav.port' => 1]);
        try {
            (new VirusScanDocumentJob($doc))->handle();
            throw new RuntimeException('Scanner outage was accepted');
        } catch (Throwable $e) {
            pilotAssert($doc->fresh()->scan_state === 'error', 'Scanner outage did not fail closed');
        } finally {
            config(['services.clamav.port' => 3310]);
        }
    } else {
        (new VirusScanDocumentJob($doc))->handle();
    }
    pilotAssert($doc->fresh()->scan_state === $state && $doc->fresh()->status === 'draft', 'Scan state or editorial state mismatch');
    if ($state === 'clean') {
        $clean = $doc->fresh();
    }
}
$pending = app(ArchiveWorkflow::class)->transition($clean, $clerk, 'pending_review', 'draft');
try {
    app(ArchiveWorkflow::class)->transition($pending, $clerk, 'published', 'pending_review');
    throw new RuntimeException('Self-review unexpectedly succeeded');
} catch (HttpException $e) {
    pilotAssert($e->getStatusCode() === 403, 'Unexpected self-review failure');
}
auth()->login($judge);
app(ArchiveWorkflow::class)->transition($pending, $judge, 'published', 'pending_review');
$head = app(ArchiveAudit::class)->verify();
file_put_contents(getenv('PILOT_CHECKPOINT_FILE'), json_encode($head, JSON_THROW_ON_ERROR));
echo "Real infrastructure checks passed: private S3 immutable bytes, ClamAV clean/EICAR/outage, separate review and audit chain.\n";
