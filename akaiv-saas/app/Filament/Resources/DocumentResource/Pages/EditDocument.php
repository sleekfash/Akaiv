<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\VirusScanDocumentJob;
use App\Services\DocumentStoredFile;
use Filament\Resources\Pages\EditRecord;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['storage_path'] ?? $this->record->storage_path) !== $this->record->storage_path) {
            $data = array_merge($data, app(DocumentStoredFile::class)->inspect($data['storage_path'], 's3'));
            $metadata = $this->record->metadata ?? [];
            unset($metadata['extraction'], $metadata['virus_signature']);
            $data['metadata'] = $metadata;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('storage_path')) {
            VirusScanDocumentJob::dispatch($this->record)->afterCommit();
        }
    }
}
