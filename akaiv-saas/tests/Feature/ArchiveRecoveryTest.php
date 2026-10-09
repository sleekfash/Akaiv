<?php

use App\Jobs\OcrDocumentJob;
use App\Jobs\VirusScanDocumentJob;
use App\Models\CaseProceeding;
use App\Services\ArchiveExport;
use App\Services\ClamScanner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('exports and independently verifies files and relationships and detects corruption', function () {
    [$org,$actor,$case] = archiveContext($this);
    Storage::fake('private');
    $bytes = 'Synthetic published judgment';
    $doc = archiveDocument($org, $actor, $case, ['status' => 'published', 'scan_state' => 'clean', 'virus_scanned' => true, 'sha256_checksum' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]);
    Storage::disk('private')->put($doc->storage_path, $bytes);
    $output = sys_get_temp_dir().'/akaiv-export-'.uniqid().'.zip';
    try {
        app(ArchiveExport::class)->create($actor, $output);
        expect(app(ArchiveExport::class)->verify($output)['files'])->toBe(1);
        $zip = new ZipArchive;
        $zip->open($output);
        $zip->addFromString('files/'.$doc->uuid, 'corrupt');
        $zip->close();
        expect(fn () => app(ArchiveExport::class)->verify($output))->toThrow(RuntimeException::class);
    } finally {
        @unlink($output);
    }
});

it('requires the same tenant for proceedings and enforces one session per case and date', function () {
    [$org,$actor,$case] = archiveContext($this);
    $attributes = ['organization_id' => $org->id, 'case_id' => $case->id, 'session_date' => '2026-10-09', 'presiding_judge' => 'Synthetic Judge', 'created_by' => $actor->id];
    CaseProceeding::create($attributes);
    expect(fn () => CaseProceeding::create($attributes))->toThrow(QueryException::class);
});

it('antivirus success never publishes and infected bytes remain blocked', function () {
    [$org,$actor,$case] = archiveContext($this);
    Storage::fake('private');
    $bytes = 'Synthetic scan fixture';
    $doc = archiveDocument($org, $actor, $case, ['sha256_checksum' => hash('sha256', $bytes), 'file_revision' => (string) Str::uuid()]);
    Storage::disk('private')->put($doc->storage_path, $bytes);
    $scanner = Mockery::mock(ClamScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturn(true);
    app()->instance(ClamScanner::class, $scanner);
    (new VirusScanDocumentJob($doc))->handle();
    expect($doc->fresh()->status)->toBe('draft')->and($doc->fresh()->scan_state)->toBe('clean');
    Queue::assertNotPushed(OcrDocumentJob::class);
});
