<?php

use App\Filament\Resources\CaseResource\Pages\CreateCase;
use App\Filament\Resources\CaseResource\Pages\EditCase;
use App\Filament\Resources\CaseResource\Pages\ListCases;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\CaseFile;
use App\Models\Document;
use App\Services\ImportCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('persists manually entered case parties through create and edit forms', function () {
    [, , $case] = archiveContext($this);
    $parties = [['role' => 'Claimant', 'name' => 'Synthetic Claimant']];
    Livewire::test(CreateCase::class)->fillForm(['title' => 'Party persistence case', 'status' => 'open', 'parties' => $parties])
        ->call('create')->assertHasNoFormErrors();
    $created = CaseFile::where('title', 'Party persistence case')->firstOrFail();
    expect($created->fresh()->parties)->toBe($parties);
    $corrected = [['role' => 'Respondent', 'name' => 'Synthetic Corrected Party']];
    Livewire::test(EditCase::class, ['record' => $case->id])->fillForm(['parties' => $corrected])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->parties)->toBe($corrected);
});

it('validates the required case title in the manual entry form', function () {
    archiveContext($this);
    Livewire::test(CreateCase::class)->fillForm(['title' => '', 'status' => 'open', 'parties' => []])
        ->call('create')->assertHasFormErrors(['title' => 'required']);
});

it('searches case metadata and filters lifecycle states without interpreting SQL input', function () {
    [$org, $actor, $case] = archiveContext($this);
    $other = CaseFile::create(['organization_id' => $org->id, 'title' => 'Distinct synthetic case', 'status' => 'closed', 'created_by' => $actor->id]);
    foreach (['Synthetic case', 'Land', 'fhc / 1 / 2026', 'FHC/1/2026'] as $search) {
        Livewire::test(ListCases::class)->searchTable($search)
            ->assertCanSeeTableRecords([$case])->assertCanNotSeeTableRecords([$other]);
    }
    Livewire::test(ListCases::class)->filterTable('status', 'closed')
        ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$case]);
    Livewire::test(ListCases::class)->searchTable("' OR 1=1 --")
        ->assertCanNotSeeTableRecords([$case, $other]);
});

it('filters document type and delivery dates within the authorized list', function () {
    [$org, $actor, $case] = archiveContext($this);
    $judgment = archiveDocument($org, $actor, $case);
    $ruling = archiveDocument($org, $actor, $case, ['friendly_name' => 'Distinct ruling', 'judicial_document_type' => 'ruling', 'date_delivered' => '2026-09-01']);
    Livewire::test(ListDocuments::class)->filterTable('judicial_document_type', 'judgment')
        ->assertCanSeeTableRecords([$judgment])->assertCanNotSeeTableRecords([$ruling]);
    Livewire::test(ListDocuments::class)->filterTable('delivery_dates', ['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$judgment])->assertCanNotSeeTableRecords([$ruling]);
});

it('rejects changed import source bytes without partial catalogue or object writes', function () {
    [$org, $actor, $case] = archiveContext($this);
    Storage::fake('s3');
    $root = sys_get_temp_dir().'/akaiv-source-change-'.uniqid();
    mkdir($root);
    file_put_contents($root.'/judgment.txt', 'Synthetic original bytes');
    try {
        $this->artisan('app:migrate-legacy-documents', ['--legacy-files' => $root, '--target-org-slug' => $org->slug, '--actor' => $actor->id])->assertSuccessful();
        $item = DB::table('import_items')->first();
        $auditCount = DB::table('archive_audit_events')->count();
        file_put_contents($root.'/judgment.txt', 'Synthetic changed bytes');
        $metadata = ['case_id' => $case->id, 'friendly_name' => 'Synthetic import', 'judicial_document_type' => 'judgment', 'date_delivered' => '2026-10-01'];
        try {
            app(ImportCommit::class)->item($item->id, $actor, $root, $metadata);
            $this->fail('Changed source must be rejected');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(409);
        }
        expect(Document::count())->toBe(0)
            ->and(DB::table('import_items')->where('id', $item->id)->value('status'))->toBe('awaiting_metadata')
            ->and(DB::table('archive_audit_events')->count())->toBe($auditCount)
            ->and(Storage::disk('s3')->allFiles())->toBe([])
            ->and(file_get_contents($root.'/judgment.txt'))->toBe('Synthetic changed bytes');
    } finally {
        unlink($root.'/judgment.txt');
        rmdir($root);
    }
});
