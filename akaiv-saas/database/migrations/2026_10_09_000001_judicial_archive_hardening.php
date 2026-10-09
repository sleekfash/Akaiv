<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $t) {
            $t->string('normalized_suit_number', 150)->nullable();
            $t->string('subject_matter', 256)->nullable();
            $t->boolean('is_sealed')->default(false);
            $t->unique(['organization_id', 'normalized_suit_number']);
        });
        Schema::create('case_access_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('granted_by')->constrained('users');
            $t->timestamps();
            $t->unique(['case_id', 'user_id']);
        });
        Schema::create('case_proceedings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('case_id')->constrained('cases');
            $t->date('session_date');
            $t->string('presiding_judge', 256);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->text('summary_notes')->nullable();
            $t->string('status')->default('draft');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['case_id', 'session_date']);
            $t->index(['organization_id', 'session_date']);
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('proceeding_id')->nullable()->constrained('case_proceedings');
            $t->date('date_delivered')->nullable();
            $t->string('judicial_document_type', 64)->nullable();
            $t->string('scan_state', 24)->default('pending');
            $t->foreignId('last_modified_by')->nullable()->constrained('users');
            $t->uuid('file_revision')->nullable();
        });
        Schema::create('audit_chain_heads', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('sequence')->default(0);
            $t->char('hash', 64)->nullable();
        });
        DB::table('audit_chain_heads')->insert(['id' => 1, 'sequence' => 0, 'hash' => null]);
        Schema::create('archive_audit_events', function (Blueprint $t) {
            $t->unsignedBigInteger('sequence')->primary();
            $t->uuid('event_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 64);
            $t->string('target_type', 128);
            $t->string('target_id', 128);
            $t->text('canonical_payload');
            $t->char('previous_hash', 64)->nullable();
            $t->char('current_hash', 64);
            $t->timestampTz('occurred_at', 6);
            $t->index(['organization_id', 'sequence']);
        });
        Schema::create('domain_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained();
            $t->string('event_type', 100);
            $t->json('payload');
            $t->timestampTz('created_at');
            $t->timestampTz('processed_at')->nullable();
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->string('status')->default('staged');
            $t->timestamps();
        });
        Schema::create('import_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained();
            $t->text('source_path');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->json('metadata')->nullable();
            $t->string('status')->default('awaiting_metadata');
            $t->foreignId('document_id')->nullable()->constrained();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->unique(['import_batch_id', 'sha256']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE case_proceedings ADD CONSTRAINT proceedings_status_check CHECK (status IN ('draft','published'))");
            DB::statement("ALTER TABLE documents ADD CONSTRAINT document_scan_check CHECK (scan_state IN ('pending','clean','infected','error'))");
            DB::statement("ALTER TABLE documents ADD CONSTRAINT document_type_check CHECK (judicial_document_type IS NULL OR judicial_document_type IN ('judgment','ruling','order','case_file','transcript'))");
            DB::statement('ALTER TABLE documents ADD CONSTRAINT document_single_parent CHECK (case_id IS NULL OR proceeding_id IS NULL)');
            DB::unprepared(<<<'SQL'
CREATE FUNCTION archive_audit_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Archive audit events are append only'; END $$;
CREATE TRIGGER archive_audit_immutable BEFORE UPDATE OR DELETE ON archive_audit_events
FOR EACH ROW EXECUTE FUNCTION archive_audit_immutable();
CREATE TRIGGER archive_audit_no_truncate BEFORE TRUNCATE ON archive_audit_events
FOR EACH STATEMENT EXECUTE FUNCTION archive_audit_immutable();
SQL);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This data-bearing migration is forward-only. Restore the coordinated backup in isolation; do not drop archive data.');
    }
};
