<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Services\DocumentUploads;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data = Arr::only($data, ['friendly_name', 'folio_number', 'description', 'retention_date', 'status', 'storage_path']);

        return app(DocumentUploads::class)->consume($data['storage_path'], auth()->user(), function (array $attributes) use ($data) {
            return parent::handleRecordCreation(array_merge($data, $attributes, [
                'owner_id' => auth()->id(),
                'metadata' => ['pending_status' => in_array($data['status'] ?? '', ['draft', 'published', 'archived'], true) ? $data['status'] : 'draft'],
            ]));
        });
    }
}
