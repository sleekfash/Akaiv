<?php

namespace App\Filament\Resources\ProceedingResource\Pages;

use App\Filament\Resources\ProceedingResource;
use App\Models\CaseFile;
use Filament\Resources\Pages\CreateRecord;

class CreateProceeding extends CreateRecord
{
    protected static string $resource = ProceedingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $case = CaseFile::findOrFail($data['case_id']);
        abort_unless(auth()->user()->can('update', $case), 403);

        return array_merge($data, ['created_by' => auth()->id(), 'organization_id' => $case->organization_id, 'status' => 'draft']);
    }
}
