<?php

use App\Models\Document;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

function tenantOrg(string $slug): Organization
{
    return Organization::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'contact_email' => $slug.'@example.test',
    ]);
}

function tenantDoc(Organization $organization, string $name): Document
{
    return Document::create([
        'organization_id' => $organization->id,
        'friendly_name' => $name,
        'original_filename' => $name.'.pdf',
        'slug' => $name,
        'storage_disk' => 'private',
        'storage_path' => 'documents/'.$name.'.pdf',
        'status' => 'published',
    ]);
}

it('scopes queries to the active organization', function (): void {
    $orgA = tenantOrg('org-a');
    $orgB = tenantOrg('org-b');
    $docA = tenantDoc($orgA, 'alpha');
    $docB = tenantDoc($orgB, 'beta');

    $user = User::create(['name' => 'A User', 'email' => 'a@example.test', 'password' => 'password']);
    $user->organizations()->attach($orgA->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['active_organization_id' => $orgA->id]);

    $visible = Document::query()->pluck('id')->all();

    expect($visible)->toContain($docA->id)
        ->and($visible)->not->toContain($docB->id);
});

it('can bypass tenancy with the withoutTenancy macro', function (): void {
    $orgA = tenantOrg('macro-a');
    $orgB = tenantOrg('macro-b');
    tenantDoc($orgA, 'macro-alpha');
    tenantDoc($orgB, 'macro-beta');

    $user = User::create(['name' => 'Macro User', 'email' => 'macro@example.test', 'password' => 'password']);
    $user->organizations()->attach($orgA->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['active_organization_id' => $orgA->id]);

    expect(Document::query()->count())->toBe(1)
        ->and(Document::withoutTenancy()->count())->toBe(2);
});

it('auto-sets organization_id on create from the active organization', function (): void {
    $org = tenantOrg('auto-org');
    $user = User::create(['name' => 'Auto User', 'email' => 'auto@example.test', 'password' => 'password']);
    $user->organizations()->attach($org->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['active_organization_id' => $org->id]);

    $folder = Folder::create(['name' => 'Filings']);

    expect((int) $folder->organization_id)->toBe($org->id);
});
