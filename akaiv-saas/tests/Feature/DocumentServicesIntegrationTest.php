<?php

use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function () {
    if (getenv('AKAIV_RUN_SERVICES') !== '1') {
        $this->markTestSkipped('Real services are opt-in.');
    }
});

it('runs clean and infected documents through real storage antivirus queues and search', function () {
    expect(config('queue.default'))->toBe('redis');
    expect(config('scout.driver'))->toBe('meilisearch');
    $disk = Storage::disk('s3');
    $disk->getClient()->createBucket(['Bucket' => config('filesystems.disks.s3.bucket')]);
    $org = Organization::create(['contact_email' => 'services@example.test', 'name' => 'Service integration', 'slug' => 'service-integration']);
    $documents = [];
    // EICAR is a harmless industry-standard antivirus test string, assembled only in disposable CI.
    $infected = 'X5O!P%@AP[4'.'\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    foreach (['clean' => 'AkaivServiceSentinel verified archive text', 'infected' => $infected] as $kind => $content) {
        $path = 'service-tests/'.Str::uuid().'.txt';
        expect($disk->put($path, $content, ['visibility' => 'private']))->toBeTrue();
        $documents[$kind] = Document::create([
            'organization_id' => $org->id, 'friendly_name' => 'Service '.$kind, 'original_filename' => $kind.'.txt',
            'storage_disk' => 's3', 'storage_path' => $path, 'file_extension' => 'txt', 'mime_type' => 'text/plain',
            'sha256_checksum' => hash('sha256', $content), 'status' => 'uploading', 'ocr_required' => true,
        ]);
    }
    expect(Queue::connection('redis')->size())->toBe(2);
    $deadline = microtime(true) + 90;
    do {
        $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--stop-when-empty', '--sleep=1', '--tries=1', '--timeout=120'], base_path());
        $worker->setTimeout(150)->mustRun();
        if (Queue::connection('redis')->size() === 0) {
            break;
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(Queue::connection('redis')->size())->toBe(0);
    $clean = $documents['clean']->fresh();
    $bad = $documents['infected']->fresh();
    expect($clean->virus_scanned)->toBeTrue()->and($clean->virus_found)->toBeFalse()
        ->and($clean->ocr_completed)->toBeTrue()->and($clean->extracted_text)->toContain('AkaivServiceSentinel');
    expect($bad->status)->toBe('quarantined')->and($bad->virus_found)->toBeTrue()->and($bad->ocr_completed)->toBeFalse();
    $client = new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key'));
    $index = $client->index($clean->searchableAs());
    $deadline = microtime(true) + 20;
    do {
        $hits = $index->search('AkaivServiceSentinel')->getHits();
        if (count($hits) === 1) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    expect($hits)->toHaveCount(1)->and((int) $hits[0]['id'])->toBe($clean->id)
        ->and((int) $hits[0]['organization_id'])->toBe($org->id);
    expect($index->search('')->getHits())->toHaveCount(1);
    $unsigned = rtrim(config('filesystems.disks.s3.endpoint'), '/').'/'.config('filesystems.disks.s3.bucket').'/'.$clean->storage_path;
    expect(Http::get($unsigned)->status())->toBeIn([401, 403]);
    expect(Http::get($disk->temporaryUrl($clean->storage_path, now()->addMinute()))->body())->toBe($clean->extracted_text);
    $outage = Document::withoutEvents(fn () => Document::create([
        'uuid' => (string) Str::uuid(), 'slug' => 'scanner-outage',
        'organization_id' => $org->id, 'friendly_name' => 'Scanner outage', 'original_filename' => 'outage.txt',
        'storage_disk' => 's3', 'storage_path' => $clean->storage_path, 'file_extension' => 'txt',
        'sha256_checksum' => $clean->sha256_checksum, 'status' => 'uploading', 'ocr_required' => true,
    ]));
    $job = new VirusScanDocumentJob($outage);
    $job->tries = 1;
    dispatch($job);
    $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--stop-when-empty', '--sleep=1'], base_path(), ['CLAMAV_PORT' => '9']);
    $worker->setTimeout(60)->mustRun();
    expect($outage->fresh()->status)->toBe('quarantined')->and($outage->fresh()->virus_scanned)->toBeFalse()
        ->and($outage->fresh()->ocr_completed)->toBeFalse();
    expect(DB::table('failed_jobs')->count())->toBe(1);
    expect(Queue::connection('redis')->size())->toBe(0);
});
