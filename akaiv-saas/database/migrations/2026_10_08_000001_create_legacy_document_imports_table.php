<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_document_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('source_system', 80);
            $table->unsignedBigInteger('legacy_document_id');
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('source_fingerprint', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'source_system', 'legacy_document_id'], 'legacy_document_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_document_imports');
    }
};
