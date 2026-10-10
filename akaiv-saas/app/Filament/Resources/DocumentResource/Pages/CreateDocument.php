<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentStoredFile;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return array_merge($data, app(DocumentStoredFile::class)->inspect($data['storage_path'], 's3'), [
            'owner_id' => auth()->id(),
            'uploaded_by' => auth()->id(),
        ]);
    }
}
