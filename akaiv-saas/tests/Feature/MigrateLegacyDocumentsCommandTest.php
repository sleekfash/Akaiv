<?php

namespace Tests\Feature;

use App\Jobs\VirusScanDocumentJob;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class MigrateLegacyDocumentsCommandTest extends TestCase
{
    use DatabaseMigrations;

    private string $root;

    private array $mapping;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 's3', 'app.timezone' => 'Africa/Lagos',
            'database.connections.legacy_mysql' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('legacy_mysql');
        Storage::fake('s3');
        Bus::fake();
        $this->root = sys_get_temp_dir().'/akaiv-legacy-test-'.bin2hex(random_bytes(12));
        mkdir($this->root.'/Owner/Records', 0700, true);
        file_put_contents($this->root.'/Owner/Records/source.txt', 'A preserved document.');
        $this->organization = Organization::create(['name' => 'Court', 'slug' => 'default', 'contact_email' => 'court@example.test']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'test-password-only']);
        $this->owner->organizations()->attach($this->organization->id, ['role' => 'member_read']);
        $folder = Folder::create(['organization_id' => $this->organization->id, 'name' => 'Records', 'created_by' => $this->owner->id]);
        $this->mapping = ['source_system' => 'legacy_fixture', 'organization_slug' => 'default',
            'source_timezone' => 'UTC', 'users' => ['11' => $this->owner->id], 'folders' => ['21' => $folder->id]];
        $schema = Schema::connection('legacy_mysql');
        $schema->create('users', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('active');
        });
        $schema->create('folders', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('user_id');
            $table->integer('active');
            $table->string('name');
        });
        $schema->create('documents', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('user_id');
            $table->integer('folder_id')->nullable();
            foreach (['name', 'file', 'created_by', 'status', 'description', 'created_at', 'updated_at'] as $column) {
                $table->string($column);
            }
            $table->string('folio_number')->nullable();
            $table->string('folder')->nullable();
        });
        DB::connection('legacy_mysql')->table('users')->insert(['id' => 11, 'active' => 1]);
        DB::connection('legacy_mysql')->table('folders')->insert(['id' => 21, 'user_id' => 11, 'active' => 1, 'name' => 'Records']);
        DB::connection('legacy_mysql')->table('documents')->insert($this->sourceRow());
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_mysql');
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function sourceRow(array $changes = []): array
    {
        return array_replace(['id' => 31, 'user_id' => 11, 'folder_id' => 21, 'name' => 'Original title',
            'file' => 'source.txt', 'created_by' => 'Owner', 'folder' => 'Records', 'status' => 'Available',
            'folio_number' => 'F-123', 'description' => 'Original description',
            'created_at' => '2021-01-23 23:28:26', 'updated_at' => '2021-01-24 10:00:00'], $changes);
    }

    private function runImport(array $options = []): PendingCommand
    {
        file_put_contents($this->root.'/mapping.json', json_encode($this->mapping, JSON_THROW_ON_ERROR));

        return $this->artisan('app:migrate-legacy-documents', array_merge([
            '--legacy-files' => $this->root, '--mapping' => $this->root.'/mapping.json',
            '--target-org-slug' => 'default',
        ], $options));
    }

    private function assertNoImports(): void
    {
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('legacy_document_imports', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        Bus::assertNothingDispatched();
    }

    public function test_dry_run_has_no_database_storage_or_job_side_effects(): void
    {
        $this->runImport(['--dry-run' => true])->assertSuccessful();
        $this->assertNoImports();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('folders', 1);
        $this->assertDatabaseCount('organization_user', 1);
    }

    public function test_import_preserves_metadata_dates_and_private_content(): void
    {
        $this->runImport()->assertSuccessful();
        $document = Document::firstOrFail();
        $this->assertSame('Original title', $document->friendly_name);
        $this->assertSame('Original description', $document->description);
        $this->assertSame('F-123', $document->folio_number);
        $this->assertSame('2021-01-24 00:28:26', $document->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2021-01-24 11:00:00', $document->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('uploading', $document->status);
        $this->assertFalse($document->shouldBeSearchable());
        $this->assertSame('private', Storage::disk('s3')->getVisibility($document->storage_path));
        $this->assertSame('A preserved document.', Storage::disk('s3')->get($document->storage_path));
        $this->assertSame(hash('sha256', 'A preserved document.'), $document->sha256_checksum);
        $this->assertDatabaseCount('legacy_document_imports', 1);
        Bus::assertDispatched(VirusScanDocumentJob::class, 1);
    }

    public function test_rerun_is_idempotent_without_collapsing_distinct_records(): void
    {
        file_put_contents($this->root.'/Owner/Records/second.txt', 'A preserved document.');
        DB::connection('legacy_mysql')->table('documents')->insert($this->sourceRow(['id' => 32, 'file' => 'second.txt', 'name' => 'Another record']));
        $this->runImport()->assertSuccessful();
        $this->runImport()->assertSuccessful();
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('legacy_document_imports', 2);
        $this->assertCount(2, Storage::disk('s3')->allFiles());
        Bus::assertDispatched(VirusScanDocumentJob::class, 2);
    }

    public function test_changed_source_is_not_silently_reimported(): void
    {
        $this->runImport()->assertSuccessful();
        DB::connection('legacy_mysql')->table('documents')->update(['description' => 'Changed']);
        $this->runImport()->assertFailed();
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame('Original description', Document::firstOrFail()->description);
    }

    public function test_deleted_records_and_unreferenced_files_are_not_imported(): void
    {
        DB::connection('legacy_mysql')->table('documents')->update(['status' => 'Deleted']);
        file_put_contents($this->root.'/Owner/orphan.txt', 'Do not infer ownership.');
        $this->runImport()->assertSuccessful();
        $this->assertNoImports();
    }

    public function test_missing_mapping_does_not_create_an_account(): void
    {
        $this->mapping['users'] = [];
        $this->runImport()->assertFailed();
        $this->assertNoImports();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_target_user_must_be_a_member(): void
    {
        $this->owner->organizations()->detach();
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_other_tenant_root_folder_is_rejected(): void
    {
        $other = Organization::create(['name' => 'Other', 'slug' => 'other', 'contact_email' => 'other@example.test']);
        $folder = Folder::create(['organization_id' => $other->id, 'name' => 'Records']);
        $this->mapping['folders']['21'] = $folder->id;
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_legacy_folder_owner_mismatch_is_rejected(): void
    {
        DB::connection('legacy_mysql')->table('folders')->update(['user_id' => 99]);
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_all_records_are_preflighted_before_any_writes(): void
    {
        DB::connection('legacy_mysql')->table('documents')->insert($this->sourceRow(['id' => 32, 'file' => 'missing.txt', 'name' => 'Missing']));
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_traversal_and_executable_files_are_rejected(): void
    {
        DB::connection('legacy_mysql')->table('documents')->update(['file' => '../source.txt']);
        $this->runImport()->assertFailed();
        $this->assertNoImports();
        DB::connection('legacy_mysql')->table('documents')->update(['file' => 'evil.php']);
        file_put_contents($this->root.'/Owner/Records/evil.php', '<?php echo 1;');
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_missing_legacy_database_never_falls_back_to_files(): void
    {
        Schema::connection('legacy_mysql')->drop('documents');
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_invalid_dates_are_not_replaced_with_filesystem_dates(): void
    {
        DB::connection('legacy_mysql')->table('documents')->update(['created_at' => '2021-02-30 10:00:00']);
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_title_collision_is_not_renamed_silently(): void
    {
        DB::connection('legacy_mysql')->table('documents')->insert($this->sourceRow(['id' => 32]));
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_corrupt_target_object_is_reported_on_rerun(): void
    {
        $this->runImport()->assertSuccessful();
        Storage::disk('s3')->put(Document::firstOrFail()->storage_path, 'Corrupt');
        $this->runImport()->assertFailed();
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_failed_database_insert_cleans_up_the_copied_object(): void
    {
        Document::creating(function (): void {
            throw new \RuntimeException('Simulated insert failure');
        });
        $this->runImport()->assertFailed();
        $this->assertNoImports();
    }

    public function test_image_files_are_imported_with_their_detected_type(): void
    {
        file_put_contents($this->root.'/Owner/Records/image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aEucAAAAASUVORK5CYII='));
        DB::connection('legacy_mysql')->table('documents')->update(['file' => 'image.png']);
        $this->runImport()->assertSuccessful();
        $this->assertSame('image/png', Document::firstOrFail()->mime_type);
    }
}
