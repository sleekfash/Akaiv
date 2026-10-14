<?php

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Models\User;
use App\Services\SealedCases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders the login page with the production-built theme', function () {
    $this->get('/admin/login')->assertOk()->assertSee('AKAIV');
});

it('renders case document and proceedings lists for an authorized member', function () {
    archiveContext($this);
    foreach (['cases', 'documents', 'proceedings'] as $resource) {
        $this->get('/admin/'.$resource)->assertOk();
    }
});

it('renders sealed cases for a granted reviewer with case actions', function () {
    [$org,$actor,$case] = archiveContext($this);
    $reviewer = User::create(['name' => 'Synthetic granted reviewer', 'email' => 'granted-reviewer@example.test', 'password' => 'test-secret']);
    $reviewer->organizations()->attach($org->id, ['role' => 'member_write']);
    $reviewer->givePermissionTo($actor->permissions);
    app(SealedCases::class)->seal($case, $actor, [$reviewer->id]);
    $this->actingAs($reviewer)->get('/admin/cases')->assertOk()->assertSee('Synthetic case');
});

it('keeps the draft edit widget behind the protected preview route', function () {
    [$org,$actor,$case] = archiveContext($this);
    Storage::fake('s3');
    $document = archiveDocument($org, $actor, $case, ['storage_disk' => 's3', 'mime_type' => 'text/plain']);
    Storage::disk('s3')->put($document->storage_path, 'Synthetic unscanned file');
    $editor = Livewire\Livewire::test(EditDocument::class, ['record' => $document->uuid]);
    $files = $editor->instance()->getFormUploadedFiles('data.storage_path');
    $url = array_values($files)[0]['url'];
    expect($url)->toContain('/documents/'.$document->uuid.'/preview?');
    $this->get($url)->assertNotFound();
});
