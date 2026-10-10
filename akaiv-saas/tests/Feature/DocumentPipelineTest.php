<?php

use App\Jobs\IndexDocumentJob;
use App\Jobs\OcrDocumentJob;
use App\Jobs\ThumbnailDocumentJob;
use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use App\Models\Organization;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Bus;

uses(Tests\TestCase::class, RefreshDatabase::class);

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

it('quarantines a document whose file is missing from storage', function (): void {
    Storage::fake('private');
    $document = pipelineDocument();

    (new VirusScanDocumentJob($document))->handle();

    expect($document->fresh()->status)->toBe('quarantined')
        ->and($document->fresh()->virus_scanned)->toBeFalse();
});

it('skips OCR when it has already completed', function (): void {
    Storage::fake('private');
    $document = pipelineDocument(['ocr_required' => true, 'ocr_completed' => true]);

    (new OcrDocumentJob($document))->handle(app(DocumentTextExtractor::class));

    expect($document->fresh()->ocr_completed)->toBeTrue();
});

it('skips thumbnail generation for non-pdf documents', function (): void {
    Storage::fake('private');
    $document = pipelineDocument(['file_extension' => 'txt', 'mime_type' => 'text/plain']);

    (new ThumbnailDocumentJob($document))->handle();

    expect(Storage::disk('private')->allFiles('thumbnails'))->toBeEmpty();
});

it('marks a deleted document unsearchable when indexing', function (): void {
    $document = pipelineDocument(['status' => 'deleted']);

    (new IndexDocumentJob($document))->handle();

    expect($document->shouldBeSearchable())->toBeFalse();
});

it('extracts text before dispatching its search index job', function (): void {
    Bus::fake();
    Storage::fake('private');
    $document = pipelineDocument(['status' => 'published', 'virus_scanned' => true,
        'file_extension' => 'txt', 'mime_type' => 'text/plain', 'storage_path' => 'documents/notes.txt']);
    Storage::disk('private')->put($document->storage_path, 'Complete text from the archive.');

    (new OcrDocumentJob($document))->handle(app(DocumentTextExtractor::class));

    expect($document->fresh()->extracted_text)->toBe('Complete text from the archive.')
        ->and($document->fresh()->ocr_completed)->toBeTrue();
    Bus::assertDispatched(IndexDocumentJob::class, function ($job) use ($document): bool {
        return $job->document->id === $document->id && $job->document->ocr_completed;
    });
});

it('never extracts or indexes an unscanned document', function (): void {
    Bus::fake();
    $document = pipelineDocument(['status' => 'published', 'virus_scanned' => false]);
    $extractor = Mockery::mock(DocumentTextExtractor::class);
    $extractor->shouldNotReceive('extract');

    (new OcrDocumentJob($document))->handle($extractor);

    expect($document->shouldBeSearchable())->toBeFalse();
    Bus::assertNotDispatched(IndexDocumentJob::class);
});

it('records extraction failure without claiming completion or indexing', function (): void {
    Bus::fake();
    Storage::fake('private');
    $document = pipelineDocument(['status' => 'published', 'virus_scanned' => true]);
    Storage::disk('private')->put($document->storage_path, 'Unreadable PDF');
    $extractor = Mockery::mock(DocumentTextExtractor::class);
    $extractor->shouldReceive('extract')->once()->andThrow(new RuntimeException('Conversion failed'));

    expect(fn () => (new OcrDocumentJob($document))->handle($extractor))->toThrow(RuntimeException::class);

    expect($document->fresh()->ocr_completed)->toBeFalse()
        ->and($document->fresh()->metadata['extraction']['status'])->toBe('failed');
    Bus::assertNotDispatched(IndexDocumentJob::class);
});
