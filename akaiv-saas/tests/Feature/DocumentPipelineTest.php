<?php

use App\Jobs\IndexDocumentJob;
use App\Jobs\OcrDocumentJob;
use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pipelineDocument(array $attributes = []): Document
{
    $organization = Organization::create([
        'name' => 'Pipeline Court',
        'slug' => 'pipeline-court-'.uniqid(),
        'contact_email' => 'pipeline@example.test',
    ]);

    return Document::create(array_merge([
        'organization_id' => $organization->id,
        'friendly_name' => 'Judgment',
        'original_filename' => 'judgment.pdf',
        'slug' => 'judgment-'.uniqid(),
        'storage_disk' => 'private',
        'storage_path' => 'documents/judgment.pdf',
        'file_extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'status' => 'uploading',
    ], $attributes));
}

it('fails closed when scan bytes are missing without publishing', function (): void {
    Storage::fake('private');
    Queue::fake();
    $document = pipelineDocument();
    expect(fn () => (new VirusScanDocumentJob($document))->handle())->toThrow(RuntimeException::class);
    $record = Document::withoutGlobalScopes()->find($document->id);
    expect($record->scan_state)->toBe('error')->and($record->virus_scanned)->toBeFalse()->and($record->status)->toBe('uploading');
});
it('performs no OCR in Phase 1 even when document flags request it', function (): void {
    Storage::fake('private');
    Queue::fake();
    $document = pipelineDocument(['ocr_required' => true, 'ocr_completed' => false]);
    (new OcrDocumentJob($document))->handle();
    expect(Document::withoutGlobalScopes()->find($document->id)->ocr_completed)->toBeFalse();
});
it('removes content from the search engine in Phase 1', function (): void {
    Queue::fake();
    $document = Mockery::mock(Document::class)->makePartial();
    $document->status = 'draft';
    $document->shouldReceive('unsearchable')->once();
    $document->shouldNotReceive('searchable');
    (new IndexDocumentJob($document))->handle();
});
