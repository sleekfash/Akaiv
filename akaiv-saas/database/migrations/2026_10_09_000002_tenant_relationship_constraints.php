<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['cases', 'folders', 'workspaces', 'case_proceedings', 'document_types'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tenant_identity UNIQUE (organization_id,id)");
        }
        foreach ([['case_proceedings', 'case_id', 'cases'], ['documents', 'case_id', 'cases'], ['documents', 'proceeding_id', 'case_proceedings'], ['documents', 'folder_id', 'folders'], ['documents', 'workspace_id', 'workspaces'], ['folders', 'case_id', 'cases'], ['folders', 'workspace_id', 'workspaces'], ['folders', 'parent_folder_id', 'folders'], ['cases', 'workspace_id', 'workspaces']] as [$source,$column,$target]) {
            // Existing relationships must be assessed before validation; new writes are constrained immediately.
            DB::statement("ALTER TABLE {$source} ADD CONSTRAINT {$source}_{$column}_tenant_fk FOREIGN KEY (organization_id,{$column}) REFERENCES {$target}(organization_id,id) NOT VALID");
        }
        DB::statement("ALTER TABLE cases ADD CONSTRAINT case_lifecycle_check CHECK (status IN ('open','closed','archived')) NOT VALID");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT document_editorial_check CHECK (status IN ('uploading','quarantined','draft','pending_review','published','archived','deleted')) NOT VALID");
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only integrity migration: use an isolated coordinated restore.');
    }
};
