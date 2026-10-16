<?php

namespace App\Filament\Resources\ProceedingResource\Pages;

use App\Filament\Resources\ProceedingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProceedings extends ListRecords
{
    protected static string $resource = ProceedingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
