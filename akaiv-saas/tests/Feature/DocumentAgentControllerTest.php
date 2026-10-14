<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Permission::create(['name' => 'document.view', 'guard_name' => 'web']);
    Queue::fake();
});

function agentContext(): array
{
    $organization = Organization::create([
        'name' => 'Agent Court',
        'slug' => 'agent-court-'.uniqid(),
        'contact_email' => 'agent@example.test',
    ]);
    $user = User::create(['name' => 'Agent User', 'email' => 'agent-'.uniqid().'@example.test', 'password' => 'password']);
    $user->organizations()->attach($organization->id, ['role' => 'member']);
    $document = Document::create([
        'organization_id' => $organization->id,
        'owner_id' => $user->id,
        'friendly_name' => 'Judgment',
        'original_filename' => 'judgment.pdf',
        'slug' => 'judgment-'.uniqid(),
        'storage_disk' => 'private',
        'storage_path' => 'documents/judgment.pdf',
        'status' => 'published',
        'virus_scanned' => true,
    ]);

    return [$organization, $user, $document];
}

it('rejects unauthenticated analysis requests', function (): void {
    [, , $document] = agentContext();

    $this->postJson('/documents/'.$document->uuid.'/analyze')->assertUnauthorized();
});

it('does not contact the worker in Phase 1 even when configured', function (): void {
    [$organization, $user, $document] = agentContext();
    session(['active_organization_id' => $organization->id]);
    config(['services.document_agent.url' => 'https://worker.example.test', 'services.document_agent.secret' => 'synthetic-test-secret']);
    Http::fake();
    $this->actingAs($user)->postJson('/documents/'.$document->uuid.'/analyze')->assertNotFound();
    Http::assertNothingSent();
});
