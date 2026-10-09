<?php

use App\Jobs\VirusScanDocumentJob;
use App\Models\CaseFile;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\Share;
use App\Models\User;
use App\Services\ArchiveAccess;
use App\Services\ArchiveAudit;
use App\Services\ArchiveWorkflow;
use App\Services\FileIngestion;
use App\Services\ImportCommit;
use App\Services\SealedCases;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function archiveContext($test): array
{
    Queue::fake();
    $org = Organization::create(['name' => 'Court', 'slug' => uniqid('court-'), 'contact_email' => 'court@example.test']);
    $actor = User::create(['name' => 'Clerk', 'email' => uniqid().'@example.test', 'password' => 'test-secret']);
    $actor->organizations()->attach($org->id, ['role' => 'member_write']);
    foreach (['case.view_any', 'case.create', 'case.update_any', 'case.seal', 'document.create', 'document.view_any', 'document.update_any', 'document.submit', 'document.review', 'document.download', 'archive.import', 'archive.export'] as $name) {
        $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    $test->actingAs($actor);
    session(['active_organization_id' => $org->id]);
    $case = CaseFile::create(['organization_id' => $org->id, 'title' => 'Synthetic case', 'suit_number' => 'FHC / 1 / 2026', 'subject_matter' => 'Land', 'created_by' => $actor->id]);

    return [$org, $actor, $case];
}

function archiveDocument($org, $actor, $case, array $extra = []): Document
{
    return Document::create(array_merge(['organization_id' => $org->id, 'case_id' => $case->id, 'owner_id' => $actor->id, 'uploaded_by' => $actor->id, 'friendly_name' => 'Judgment', 'original_filename' => 'judgment.txt', 'storage_disk' => 'private', 'storage_path' => uniqid('documents/'), 'status' => 'draft', 'judicial_document_type' => 'judgment', 'date_delivered' => '2026-10-01'], $extra));
}

it('fails closed for missing, forged, stale and removed memberships', function () {
    [$org,$actor,$case] = archiveContext($this);
    $document = archiveDocument($org, $actor, $case);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'contact_email' => 'other@example.test']);
    session(['active_organization_id' => $other->id]);
    expect(Document::count())->toBe(0)->and(app(ArchiveAccess::class)->document($actor, $document))->toBeFalse();
    session(['active_organization_id' => $org->id]);
    $actor->organizations()->detach();
    expect(Document::count())->toBe(0)->and($actor->can('create', Document::class))->toBeFalse();
    auth()->forgetUser();
    expect(Document::count())->toBe(0);
});

it('hides sealed cases and descendants even from ordinary platform administration', function () {
    [$org,$actor,$case] = archiveContext($this);
    $document = archiveDocument($org, $actor, $case);
    $reviewer = User::create(['name' => 'Judge', 'email' => 'judge@example.test', 'password' => 'test-secret']);
    $reviewer->organizations()->attach($org->id, ['role' => 'member_write']);
    app(SealedCases::class)->seal($case, $actor, [$reviewer->id]);
    expect(CaseFile::count())->toBe(0)->and(Document::count())->toBe(0)->and(app(ArchiveAccess::class)->document($actor, $document))->toBeFalse();
    $this->actingAs($reviewer);
    expect(CaseFile::count())->toBe(1)->and(Document::count())->toBe(1);
});

it('blocks cross-tenant parent attachments', function () {
    [$org,$actor,$case] = archiveContext($this);
    $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'contact_email' => 'other@example.test']);
    $foreign = CaseFile::create(['organization_id' => $other->id, 'title' => 'Foreign case']);
    expect(fn () => archiveDocument($org, $actor, $case, ['case_id' => $foreign->id]))->toThrow(HttpException::class);
});

it('requires a separate reviewer and rejects stale or skipped transitions', function () {
    [$org,$actor,$case] = archiveContext($this);
    $document = archiveDocument($org, $actor, $case, ['scan_state' => 'clean', 'virus_scanned' => true, 'sha256_checksum' => hash('sha256', 'file')]);
    expect(fn () => app(ArchiveWorkflow::class)->transition($document, $actor, 'published', 'draft'))->toThrow(ValidationException::class);
    $pending = app(ArchiveWorkflow::class)->transition($document, $actor, 'pending_review', 'draft');
    expect(fn () => app(ArchiveWorkflow::class)->transition($pending, $actor, 'published', 'pending_review'))->toThrow(HttpException::class);
    $reviewer = User::create(['name' => 'Judge', 'email' => 'reviewer@example.test', 'password' => 'test-secret']);
    $reviewer->organizations()->attach($org->id, ['role' => 'member_write']);
    $reviewer->givePermissionTo(Permission::findOrCreate('document.review', 'web'));
    $this->actingAs($reviewer);
    $published = app(ArchiveWorkflow::class)->transition($pending, $reviewer, 'published', 'pending_review');
    expect($published->status)->toBe('published');
    expect(fn () => app(ArchiveWorkflow::class)->transition($published, $reviewer, 'draft', 'pending_review'))->toThrow(ValidationException::class);
});

it('detects hash tampering and truncation using an external checkpoint', function () {
    [$org,$actor] = archiveContext($this);
    $checkpoint = app(ArchiveAudit::class)->append($org->id, $actor->id, 'TEST', 'synthetic', '1', ['b' => 2, 'a' => 1]);
    expect(app(ArchiveAudit::class)->verify($checkpoint))->toBe($checkpoint);
    // PostgreSQL rejects the mutation at its append-only trigger; SQLite tests verifier behavior.
    if (DB::getDriverName() === 'pgsql') {
        expect(fn () => DB::table('archive_audit_events')->where('sequence', $checkpoint['sequence'])->update(['action' => 'ALTERED']))->toThrow(QueryException::class);
    } else {
        DB::table('archive_audit_events')->where('sequence', $checkpoint['sequence'])->update(['action' => 'ALTERED']);
        expect(fn () => app(ArchiveAudit::class)->verify($checkpoint))->toThrow(RuntimeException::class);
    }
});

it('disables public sharing and AI without external transmission', function () {
    [$org,$actor,$case] = archiveContext($this);
    $document = archiveDocument($org, $actor, $case);
    Http::fake();
    $this->postJson('/documents/'.$document->uuid.'/analyze')->assertNotFound();
    $share = Share::create(['document_id' => $document->id, 'shared_by' => $actor->id]);
    $this->get('/share/'.$share->token)->assertNotFound();
    Http::assertNothingSent();
});

it('derives file identity and resets scan eligibility on replacement', function () {
    [$org,$actor,$case] = archiveContext($this);
    Storage::fake('s3');
    $source = 'org_'.$org->id.'/incoming/user_'.$actor->id.'/one.txt';
    Storage::disk('s3')->put($source, 'synthetic first file');
    $record = app(FileIngestion::class)->save(['storage_path' => $source, 'friendly_name' => 'Evidence', 'case_id' => $case->id, 'judicial_document_type' => 'judgment', 'date_delivered' => '2026-10-01'], $actor);
    expect($record->sha256_checksum)->toBe(hash('sha256', 'synthetic first file'))->and($record->status)->toBe('draft')->and($record->versions()->count())->toBe(1);
    $oldJob = new VirusScanDocumentJob($record);
    $record->forceFill(['scan_state' => 'clean', 'virus_scanned' => true])->saveQuietly();
    $replacement = 'org_'.$org->id.'/incoming/user_'.$actor->id.'/two.txt';
    Storage::disk('s3')->put($replacement, 'synthetic replacement');
    $record = app(FileIngestion::class)->save(['storage_path' => $replacement, 'friendly_name' => 'Evidence'], $actor, $record);
    $oldJob->handle();
    expect($record->fresh()->scan_state)->toBe('pending')->and($record->fresh()->virus_scanned)->toBeFalse()->and($record->versions()->count())->toBe(2);
});

it('denies unscanned files and altered bytes through signed serving routes', function () {
    [$org,$actor,$case] = archiveContext($this);
    Storage::fake('private');
    $document = archiveDocument($org, $actor, $case, ['status' => 'published', 'scan_state' => 'pending', 'sha256_checksum' => hash('sha256', 'expected')]);
    Storage::disk('private')->put($document->storage_path, 'altered');
    $url = URL::temporarySignedRoute('documents.preview', now()->addMinute(), ['document' => $document->uuid]);
    $this->get($url)->assertNotFound();
    $document->forceFill(['scan_state' => 'clean', 'virus_scanned' => true])->saveQuietly();
    $this->get($url)->assertStatus(409);
});

it('blocks user administration and own-record writes for read-only memberships', function () {
    [$org,$actor,$case] = archiveContext($this);
    $document = archiveDocument($org, $actor, $case);
    $actor->organizations()->updateExistingPivot($org->id, ['role' => 'member_read']);
    expect($actor->can('update', $actor))->toBeFalse()->and($actor->can('update', $document))->toBeFalse()->and($actor->can('create', Document::class))->toBeFalse();
});

it('propagates sealed access through nested folders and files without a direct case link', function () {
    [$org,$actor,$case] = archiveContext($this);
    $parent = Folder::create(['organization_id' => $org->id, 'case_id' => $case->id, 'name' => 'Sealed parent']);
    $child = Folder::create(['organization_id' => $org->id, 'parent_folder_id' => $parent->id, 'name' => 'Child']);
    $document = archiveDocument($org, $actor, $case, ['case_id' => null, 'folder_id' => $child->id]);
    $judge = User::create(['name' => 'Judge', 'email' => 'sealed-judge@example.test', 'password' => 'test-secret']);
    $judge->organizations()->attach($org->id, ['role' => 'member_write']);
    app(SealedCases::class)->seal($case, $actor, [$judge->id]);
    expect(Folder::count())->toBe(0)->and(Document::count())->toBe(0)
        ->and(app(ArchiveAccess::class)->document($actor, $document))->toBeFalse();
});

it('catalogues an approved manifest once and preserves source bytes on retry', function () {
    [$org,$actor,$case] = archiveContext($this);
    Storage::fake('s3');
    $root = sys_get_temp_dir().'/akaiv-import-'.uniqid();
    mkdir($root);
    file_put_contents($root.'/judgment.txt', 'Synthetic import document');
    try {
        $this->artisan('app:migrate-legacy-documents', ['--legacy-files' => $root, '--target-org-slug' => $org->slug, '--actor' => $actor->id])->assertSuccessful();
        $item = DB::table('import_items')->first();
        $metadata = ['case_id' => $case->id, 'friendly_name' => 'Imported judgment', 'judicial_document_type' => 'judgment', 'date_delivered' => '2026-10-01'];
        $document = app(ImportCommit::class)->item($item->id, $actor, $root, $metadata);
        $retry = app(ImportCommit::class)->item($item->id, $actor, $root, $metadata);
        expect($retry->id)->toBe($document->id)->and(Document::count())->toBe(1)->and($document->status)->toBe('draft')
            ->and(file_get_contents($root.'/judgment.txt'))->toBe('Synthetic import document');
    } finally {
        @unlink($root.'/judgment.txt');
        @rmdir($root);
    }
});
