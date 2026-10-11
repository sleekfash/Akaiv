<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('private');
    Http::fake(['*' => Http::response(['summary' => 'Allowed analysis'])]);
    config(['services.document_agent.url' => 'https://worker.example.test', 'services.document_agent.secret' => 'synthetic-test']);
    foreach (['document.view', 'document.download'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
    }
    $organization = Organization::create(['name' => 'Access test', 'slug' => 'access-test', 'contact_email' => 'access@example.test']);
    $user = User::create(['name' => 'Access user', 'email' => 'access@example.test', 'password' => 'test-password']);
    $user->organizations()->attach($organization->id, ['role' => 'member']);
    $user->givePermissionTo(['document.view', 'document.download']);
    $this->actingAs($user);
    session(['active_organization_id' => $organization->id]);
    $this->document = Document::create([
        'organization_id' => $organization->id, 'owner_id' => $user->id,
        'friendly_name' => 'Restricted content', 'original_filename' => 'restricted.txt',
        'storage_disk' => 'private', 'storage_path' => 'documents/restricted.txt', 'mime_type' => 'text/plain',
        'status' => 'published', 'virus_scanned' => true, 'extracted_text' => 'Private text',
    ]);
    Storage::disk('private')->put($this->document->storage_path, 'Private text');
    $this->share = Share::create(['document_id' => $this->document->id, 'shared_by' => $user->id,
        'can_preview' => true, 'can_download' => true]);
});

function contentAccessRequest(TestCase $test, string $channel)
{
    if ($channel === 'analysis') {
        return $test->postJson(route('documents.analyze', $test->document));
    }
    if ($channel === 'share') {
        return $test->get(route('shares.show', $test->share));
    }

    return $test->get(URL::temporarySignedRoute('documents.'.$channel, now()->addMinute(), ['document' => $test->document->uuid]));
}

it('blocks all content channels despite a previously clean scan', function (string $channel, array $attributes) {
    $this->document->updateQuietly($attributes);
    contentAccessRequest($this, $channel)->assertNotFound();
    Http::assertNothingSent();
    expect($this->document->fresh()->view_count)->toBe(0)
        ->and($this->document->fresh()->download_count)->toBe(0)
        ->and($this->share->fresh()->access_count)->toBe(0);
})->with(['preview', 'download', 'share', 'analysis'])->with([
    'administrative quarantine' => [['status' => 'quarantined']],
    'uploading' => [['status' => 'uploading']],
    'deleted' => [['status' => 'deleted']],
    'unscanned' => [['virus_scanned' => false]],
    'infected' => [['virus_found' => true]],
]);

it('preserves authorized access to clean content', function (string $channel, string $status) {
    $this->document->updateQuietly(['status' => $status]);
    $response = contentAccessRequest($this, $channel)->assertOk();
    if ($channel === 'analysis') {
        Http::assertSentCount(1);
    } else {
        $response->assertStreamedContent('Private text');
    }
})->with(['preview', 'download', 'share', 'analysis'])->with(['published', 'draft', 'archived']);

it('blocks old signed and shared links after administrative quarantine', function () {
    $url = URL::temporarySignedRoute('documents.preview', now()->addMinute(), ['document' => $this->document->uuid]);
    $this->get($url)->assertOk();
    $this->document->update(['status' => 'quarantined']);
    $this->get($url)->assertNotFound();
    $this->get(route('shares.show', $this->share))->assertNotFound();
});
