<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Permission::create(['name' => 'document.view', 'guard_name' => 'web']);
    Queue::fake();
});

it('serves a scanned private document through a valid signed preview URL', function (): void {
    Storage::fake('private');
    $organization = Organization::create([
        'name' => 'Preview Court',
        'slug' => 'preview-court',
        'contact_email' => 'preview@example.test',
    ]);
    $user = User::create([
        'name' => 'Preview User',
        'email' => 'preview-user@example.test',
        'password' => 'password',
    ]);
    $user->organizations()->attach($organization->id, ['role' => 'member']);
    $document = Document::create([
        'organization_id' => $organization->id,
        'owner_id' => $user->id,
        'friendly_name' => 'Judgment',
        'original_filename' => 'judgment.txt',
        'slug' => 'judgment-preview',
        'storage_disk' => 'private',
        'storage_path' => 'documents/judgment.txt',
        'mime_type' => 'text/plain',
        'status' => 'published',
        'virus_scanned' => true,
        'scan_state' => 'clean',
        'sha256_checksum' => hash('sha256', 'confidential judgment'),
    ]);
    Storage::disk('private')->put($document->storage_path, 'confidential judgment');

    session(['active_organization_id' => $organization->id]);

    $response = $this->actingAs($user)->get(
        URL::temporarySignedRoute('documents.preview', now()->addMinute(), ['document' => $document->uuid]),
    );

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertStreamedContent('confidential judgment');
});

it('rejects an invalid preview signature', function (): void {
    $organization = Organization::create([
        'name' => 'Signature Court',
        'slug' => 'signature-court',
        'contact_email' => 'signature@example.test',
    ]);
    $user = User::create([
        'name' => 'Signature User',
        'email' => 'signature-user@example.test',
        'password' => 'password',
    ]);
    $user->organizations()->attach($organization->id, ['role' => 'member']);
    $document = Document::create([
        'organization_id' => $organization->id,
        'owner_id' => $user->id,
        'friendly_name' => 'Judgment',
        'original_filename' => 'judgment.txt',
        'slug' => 'judgment-signature',
        'storage_disk' => 'private',
        'storage_path' => 'documents/judgment.txt',
        'status' => 'published',
        'virus_scanned' => true,
        'scan_state' => 'clean',
        'sha256_checksum' => hash('sha256', 'confidential judgment'),
    ]);
    session(['active_organization_id' => $organization->id]);

    $response = $this->actingAs($user)->get('/documents/'.$document->uuid.'/preview');

    $response->assertForbidden();
});
