<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Jobs\VirusScanDocumentJob;
use App\Services\DocumentUploads;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $uploads = app(DocumentUploads::class);
        $organization = $uploads->organization(auth()->user());
        abort_unless((int) $record->organization_id === $organization, 403);
        $data = Arr::only($data, ['friendly_name', 'folio_number', 'description', 'retention_date', 'status', 'storage_path']);

        return DB::transaction(function () use ($record, $data, $uploads) {
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (($data['storage_path'] ?? $record->storage_path) === $record->storage_path) {
                unset($data['storage_path']);

                return parent::handleRecordUpdate($record, $data);
            }

            return $uploads->consume($data['storage_path'], auth()->user(), function (array $attributes) use ($record, $data) {
                $metadata = $record->metadata ?? [];
                unset($metadata['extraction'], $metadata['virus_signature'], $metadata['thumbnail_path'], $metadata['scan_error']);
                $metadata['pending_status'] = in_array($data['status'] ?? '', ['draft', 'published', 'archived'], true) ? $data['status'] : 'draft';
                $updated = parent::handleRecordUpdate($record, array_merge($data, $attributes, ['metadata' => $metadata]));
                VirusScanDocumentJob::dispatch($updated)->afterCommit();

                return $updated;
            });
        });
    }
}
