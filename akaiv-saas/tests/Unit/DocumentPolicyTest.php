<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use App\Policies\DocumentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function policyUser(string $email): User
{
    return User::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => 'password',
    ]);
}

function policyDocument(int $organizationId, int $ownerId): Document
{
    return Document::create([
        'organization_id' => $organizationId,
        'owner_id' => $ownerId,
        'friendly_name' => 'Judgment',
        'original_filename' => 'judgment.pdf',
        'slug' => 'judgment',
        'storage_disk' => 'private',
        'storage_path' => 'documents/judgment.pdf',
        'status' => 'published',
    ]);
}

beforeEach(function (): void {
    Queue::fake();
    Permission::create(['name' => 'document.view', 'guard_name' => 'web']);
    Permission::create(['name' => 'document.download', 'guard_name' => 'web']);
});

it('allows an owner to view a document in the active organization', function (): void {
    $organization = Organization::create([
        'name' => 'Court',
        'slug' => 'court',
        'contact_email' => 'court@example.test',
    ]);
    $user = policyUser('owner@example.test');
    $document = policyDocument($organization->id, $user->id);

    $user->organizations()->attach($organization->id, ['role' => 'member_write']);
    $this->actingAs($user);
    session(['active_organization_id' => $organization->id]);

    expect((new DocumentPolicy)->view($user, $document))->toBeTrue();
});

it('denies viewing a document outside the active organization', function (): void {
    $firstOrganization = Organization::create([
        'name' => 'First Court',
        'slug' => 'first-court',
        'contact_email' => 'first@example.test',
    ]);
    $secondOrganization = Organization::create([
        'name' => 'Second Court',
        'slug' => 'second-court',
        'contact_email' => 'second@example.test',
    ]);
    $user = policyUser('member@example.test');
    $document = policyDocument($secondOrganization->id, $user->id);

    session(['active_organization_id' => $firstOrganization->id]);

    expect((new DocumentPolicy)->view($user, $document))->toBeFalse();
});

it('requires download permission even when viewing is allowed', function (): void {
    $organization = Organization::create([
        'name' => 'Court',
        'slug' => 'download-court',
        'contact_email' => 'download@example.test',
    ]);
    $user = policyUser('viewer@example.test');
    $user->givePermissionTo('document.view');
    $document = policyDocument($organization->id, $user->id);

    $user->organizations()->attach($organization->id, ['role' => 'member_write']);
    $this->actingAs($user);
    session(['active_organization_id' => $organization->id]);

    expect((new DocumentPolicy)->view($user, $document))->toBeTrue()
        ->and((new DocumentPolicy)->download($user, $document))->toBeFalse();
});
