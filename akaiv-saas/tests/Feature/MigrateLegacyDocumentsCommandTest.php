<?php

use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Organization::create([
        'name' => 'Default Court',
        'slug' => 'default',
        'contact_email' => 'default@example.test',
    ]);
});

function legacyFixture(): string
{
    $root = sys_get_temp_dir().'/akaiv-legacy-'.uniqid();
    mkdir($root.'/John Doe/Judgments', 0777, true);
    file_put_contents($root.'/John Doe/Judgments/2021-01-23_23_28_26_Judgment.txt', 'legacy judgment');
    file_put_contents($root.'/John Doe/Judgments/evil.php', '<?php echo "pwned";');
    file_put_contents($root.'/John Doe/Judgments/.htaccess', 'Require all denied');

    return $root;
}

it('dry-run changes no users, records, storage or queued jobs', function (): void {
    Storage::fake('s3');
    Queue::fake();
    $root = legacyFixture();
    $counts = [];
    foreach (['users', 'documents', 'folders', 'import_batches', 'import_items', 'archive_audit_events'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $this->artisan('app:migrate-legacy-documents', ['--dry-run' => true, '--legacy-files' => $root, '--target-org-slug' => 'default'])->assertSuccessful();
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    expect(Storage::disk('s3')->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();
});
it('requires an explicit authorized actor and stages rather than publishes', function (): void {
    Storage::fake('s3');
    Queue::fake();
    $org = Organization::where('slug', 'default')->first();
    $actor = User::create(['name' => 'Importer', 'email' => 'importer@example.test', 'password' => 'test-secret']);
    $actor->organizations()->attach($org->id, ['role' => 'member_write']);
    $actor->givePermissionTo(Permission::findOrCreate('archive.import', 'web'));
    $root = legacyFixture();
    $this->artisan('app:migrate-legacy-documents', ['--legacy-files' => $root, '--target-org-slug' => 'default', '--actor' => $actor->id])->assertSuccessful();
    expect(DB::table('import_items')->count())->toBe(1)
        ->and(Document::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('import_items')->first()->status)->toBe('awaiting_metadata');
    expect(Storage::disk('s3')->allFiles())->toBeEmpty();
});
