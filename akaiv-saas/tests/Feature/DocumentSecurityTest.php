<?php

use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Jobs\IndexDocumentJob;
use App\Jobs\OcrDocumentJob;
use App\Jobs\ThumbnailDocumentJob;
use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentAntivirus;
use App\Services\DocumentTextExtractor;
use App\Services\DocumentThumbnailer;
use App\Services\DocumentUploads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Storage::fake('private');
    Storage::fake('s3');
});

function securedDocument(array $attributes = []): Document
{
    $org = Organization::create(['contact_email' => 'services@example.test', 'name' => 'Security', 'slug' => 'security-'.uniqid()]);
    $content = 'Verified archive document';
    $document = Document::create(array_merge([
        'organization_id' => $org->id, 'friendly_name' => 'Security test', 'original_filename' => 'test.txt',
        'storage_disk' => 'private', 'storage_path' => 'documents/'.uniqid().'.txt',
        'file_extension' => 'txt', 'mime_type' => 'text/plain', 'sha256_checksum' => hash('sha256', $content),
        'status' => 'uploading', 'ocr_required' => true,
    ], $attributes));
    Storage::disk('private')->put($document->storage_path, $content);

    return $document;
}

it('requires an explicit clean scanner verdict', function ($verdict) {
    $document = securedDocument();
    $this->mock(DocumentAntivirus::class)->shouldReceive('scan')->once()->andReturn(['verdict' => $verdict]);
    expect(fn () => (new VirusScanDocumentJob($document))->handle())->toThrow(RuntimeException::class);
    expect($document->fresh()->virus_scanned)->toBeFalse()->and($document->fresh()->status)->toBe('quarantined');
    Bus::assertNotDispatched(OcrDocumentJob::class);
    Bus::assertNotDispatched(ThumbnailDocumentJob::class);
})->with(['error', 'unknown', '']);

it('quarantines infected input without downstream work', function () {
    $document = securedDocument();
    $this->mock(DocumentAntivirus::class)->shouldReceive('scan')->andReturn(['verdict' => 'infected', 'reason' => 'Test signature']);
    (new VirusScanDocumentJob($document))->handle();
    expect($document->fresh()->virus_found)->toBeTrue()->and($document->fresh()->status)->toBe('quarantined');
    Bus::assertNotDispatched(OcrDocumentJob::class);
});

it('retries a failed scan and preserves the intended visibility', function () {
    $document = securedDocument(['metadata' => ['pending_status' => 'draft']]);
    $scanner = $this->mock(DocumentAntivirus::class);
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException('Scanner offline'));
    $scanner->shouldReceive('scan')->once()->andReturn(['verdict' => 'clean']);
    $job = new VirusScanDocumentJob($document);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->handle();
    expect($document->fresh()->status)->toBe('draft')->and($document->fresh()->virus_scanned)->toBeTrue();
    Bus::assertDispatched(OcrDocumentJob::class);
});

it('rejects stored bytes that differ from their recorded checksum', function () {
    $document = securedDocument();
    Storage::disk('private')->put($document->storage_path, 'Altered bytes');
    $this->mock(DocumentAntivirus::class)->shouldNotReceive('scan');
    expect(fn () => (new VirusScanDocumentJob($document))->handle())->toThrow(RuntimeException::class);
    expect($document->fresh()->virus_scanned)->toBeFalse();
});

it('discards a scan verdict if the document is replaced during scanning', function () {
    $document = securedDocument();
    $this->mock(DocumentAntivirus::class)->shouldReceive('scan')->andReturnUsing(function () use ($document) {
        $document->updateQuietly(['storage_path' => 'documents/replacement.txt', 'sha256_checksum' => hash('sha256', 'replacement')]);

        return ['verdict' => 'clean'];
    });
    (new VirusScanDocumentJob($document))->handle();
    expect($document->fresh()->virus_scanned)->toBeFalse();
    Bus::assertNotDispatched(OcrDocumentJob::class);
});

it('keeps the original version when a queued job is serialized', function () {
    $document = securedDocument();
    $payload = serialize(new VirusScanDocumentJob($document));
    $document->updateQuietly(['storage_path' => 'documents/replacement.txt']);
    $this->mock(DocumentAntivirus::class)->shouldNotReceive('scan');
    unserialize($payload)->handle();
    expect($document->fresh()->virus_scanned)->toBeFalse();
});

it('discards extracted text if replacement occurs during conversion', function () {
    $document = securedDocument(['virus_scanned' => true, 'status' => 'published']);
    $extractor = Mockery::mock(DocumentTextExtractor::class);
    $extractor->shouldReceive('extract')->andReturnUsing(function () use ($document) {
        $document->updateQuietly(['storage_path' => 'documents/replacement.txt', 'virus_scanned' => false]);

        return ['text' => 'Stale text', 'page_count' => 1];
    });
    (new OcrDocumentJob($document))->handle($extractor);
    expect($document->fresh()->extracted_text)->toBeNull()->and($document->fresh()->ocr_completed)->toBeFalse();
    Bus::assertNotDispatched(IndexDocumentJob::class);
});

it('skips a stale thumbnail before reading any file', function () {
    $document = securedDocument(['virus_scanned' => true, 'status' => 'published', 'file_extension' => 'pdf']);
    $job = new ThumbnailDocumentJob($document);
    $document->updateQuietly(['storage_path' => 'documents/replacement.pdf']);
    $job->handle();
    expect(Storage::disk('private')->allFiles('thumbnails'))->toBeEmpty();
});

function uploadOwner(): User
{
    $org = Organization::create(['contact_email' => 'services@example.test', 'name' => 'Uploads', 'slug' => 'uploads-'.uniqid()]);
    $user = User::create(['name' => 'Uploader', 'email' => uniqid().'@example.test', 'password' => 'test-password']);
    $user->organizations()->attach($org->id, ['role' => 'member']);
    session(['active_organization_id' => $org->id]);

    return $user;
}

it('claims a private upload only once for its owner', function () {
    $user = uploadOwner();
    $service = app(DocumentUploads::class);
    $path = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $user);
    $attributes = $service->consume($path, $user, fn ($attributes) => $attributes);
    expect($attributes['original_filename'])->toBe('notes.txt')
        ->and($attributes['sha256_checksum'])->toBe(hash('sha256', 'Owned text'))
        ->and($attributes['virus_scanned'])->toBeFalse()
        ->and(Storage::disk('s3')->getVisibility($path))->toBe('private');
    expect(fn () => $service->consume($path, $user, fn () => null))->toThrow(ValidationException::class);
});

it('rejects another users upload even within the same organization', function () {
    $owner = uploadOwner();
    $service = app(DocumentUploads::class);
    $path = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $owner);
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test-password']);
    $other->organizations()->attach(session('active_organization_id'), ['role' => 'member']);
    expect(fn () => $service->consume($path, $other, fn () => null))->toThrow(ValidationException::class);
});

it('rejects revoked membership and cross organization claims', function () {
    $owner = uploadOwner();
    $service = app(DocumentUploads::class);
    $path = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $owner);
    $owner->organizations()->detach();
    expect(fn () => $service->consume($path, $owner, fn () => null))->toThrow(ValidationException::class);
});

it('rejects forged expired and altered upload paths', function ($mode) {
    $owner = uploadOwner();
    $service = app(DocumentUploads::class);
    $path = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $owner);
    if ($mode === 'expired') {
        DB::table('document_uploads')->update(['expires_at' => now()->subMinute()]);
    } elseif ($mode === 'altered') {
        Storage::disk('s3')->put($path, 'Changed text');
    } else {
        $path = 'documents/another-record.txt';
        Storage::disk('s3')->put($path, 'Existing private document');
    }
    expect(fn () => $service->consume($path, $owner, fn () => null))->toThrow(ValidationException::class);
})->with(['forged', 'expired', 'altered']);

it('rolls back upload consumption when saving the document fails', function () {
    $user = uploadOwner();
    $service = app(DocumentUploads::class);
    $path = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $user);
    expect(fn () => $service->consume($path, $user, fn () => throw new RuntimeException('Save failed')))->toThrow(RuntimeException::class);
    expect(DB::table('document_uploads')->where('path', $path)->value('consumed_at'))->toBeNull();
    expect($service->consume($path, $user, fn ($attributes) => $attributes['storage_path']))->toBe($path);
});

it('discards and removes a thumbnail produced during file replacement', function () {
    $document = securedDocument(['virus_scanned' => true, 'status' => 'published', 'file_extension' => 'pdf']);
    $this->mock(DocumentThumbnailer::class)->shouldReceive('render')->once()->andReturnUsing(function ($input) use ($document) {
        file_put_contents($input.'.png', 'Synthetic thumbnail');
        $document->updateQuietly(['storage_path' => 'documents/new.pdf', 'virus_scanned' => false]);
    });
    (new ThumbnailDocumentJob($document))->handle();
    expect($document->fresh()->metadata['thumbnail_path'] ?? null)->toBeNull()
        ->and(Storage::disk('private')->allFiles('thumbnails'))->toBeEmpty();
});

it('recovers downstream dispatch when a successful scan job is retried', function () {
    $document = securedDocument(['virus_scanned' => true, 'status' => 'published']);
    $this->mock(DocumentAntivirus::class)->shouldNotReceive('scan');
    (new VirusScanDocumentJob($document))->handle();
    Bus::assertDispatched(OcrDocumentJob::class);
    Bus::assertDispatched(ThumbnailDocumentJob::class);
});

it('rejects a claim under another organization even when the owner belongs to both', function () {
    $user = uploadOwner();
    $service = app(DocumentUploads::class);
    $path = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $user);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'contact_email' => 'other@example.test']);
    $user->organizations()->attach($other->id, ['role' => 'member']);
    session(['active_organization_id' => $other->id]);
    expect(fn () => $service->consume($path, $user, fn () => null))->toThrow(ValidationException::class);
});

it('enforces upload ownership through the create page and ignores forged processing fields', function () {
    $user = uploadOwner();
    $this->actingAs($user);
    $path = app(DocumentUploads::class)->store(UploadedFile::fake()->createWithContent('notes.txt', 'Owned text'), $user);
    $page = new class extends CreateDocument
    {
        public function saveForTest(array $data): Model
        {
            return $this->handleRecordCreation($data);
        }
    };
    $record = $page->saveForTest(['friendly_name' => 'Owned', 'storage_path' => $path, 'status' => 'draft',
        'virus_scanned' => true, 'organization_id' => 999, 'extracted_text' => 'Forged index', 'owner_id' => 999]);
    expect($record->virus_scanned)->toBeFalse()->and($record->extracted_text)->toBeNull()
        ->and((int) $record->owner_id)->toBe($user->id)
        ->and((int) $record->organization_id)->toBe((int) session('active_organization_id'));
    expect(fn () => $page->saveForTest(['friendly_name' => 'Forged', 'storage_path' => 'documents/forged.txt']))->toThrow(ValidationException::class);
});

it('enforces upload ownership through replacement and clears previous processing results', function () {
    $user = uploadOwner();
    $this->actingAs($user);
    $document = securedDocument(['organization_id' => session('active_organization_id'), 'virus_scanned' => true,
        'status' => 'published', 'ocr_completed' => true, 'extracted_text' => 'Old content']);
    $page = new class extends EditDocument
    {
        public function saveForTest(Model $record, array $data): Model
        {
            return $this->handleRecordUpdate($record, $data);
        }
    };
    expect(fn () => $page->saveForTest($document, ['storage_path' => 'documents/forged.txt']))->toThrow(ValidationException::class);
    $path = app(DocumentUploads::class)->store(UploadedFile::fake()->createWithContent('replacement.txt', 'Replacement text'), $user);
    $updated = $page->saveForTest($document, ['storage_path' => $path, 'status' => 'draft', 'virus_scanned' => true]);
    expect($updated->virus_scanned)->toBeFalse()->and($updated->ocr_completed)->toBeFalse()
        ->and($updated->extracted_text)->toBeNull()->and($updated->status)->toBe('uploading');
    Bus::assertDispatched(VirusScanDocumentJob::class);
});
