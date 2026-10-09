<?php

namespace App\Filament\Resources;

use App\Models\ArchiveAuditEvent;
use App\Services\TenantContext;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditResource extends Resource
{
    protected static ?string $model = ArchiveAuditEvent::class;

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    public static function canViewAny(): bool
    {
        return auth()->user()?->checkPermissionTo('archive.audit') && app(TenantContext::class)->id() !== null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('organization_id', app(TenantContext::class)->id());
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sequence', 'desc')->columns([Tables\Columns\TextColumn::make('sequence'), Tables\Columns\TextColumn::make('action')->searchable(), Tables\Columns\TextColumn::make('actor_id'), Tables\Columns\TextColumn::make('target_id'), Tables\Columns\TextColumn::make('current_hash')->limit(16), Tables\Columns\TextColumn::make('occurred_at')->dateTime()])->actions([])->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => AuditResource\Pages\ListAudit::route('/')];
    }
}
