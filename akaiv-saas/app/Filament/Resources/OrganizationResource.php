<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrganizationResource\Pages;
use App\Filament\Resources\OrganizationResource\RelationManagers\UsersRelationManager;
use App\Models\Organization;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return auth()->user()?->hasRole('Platform SuperAdmin') ? $query : $query->whereHas('users', fn ($q) => $q->where('users.id', auth()->id()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identity')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->required()->maxLength(120)->unique(ignoreRecord: true),
                TextInput::make('registration_number')->maxLength(255),
                Select::make('court_type')->options([
                    'High Court' => 'High Court',
                    'Magistrate Court' => 'Magistrate Court',
                    'Customary Court of Appeal' => 'Customary Court of Appeal',
                    'Tribunal' => 'Tribunal',
                    'Law Firm' => 'Law Firm',
                ])->searchable(),
                TextInput::make('jurisdiction_state')->maxLength(100),
            ])->columns(2),
            Section::make('Contact')->schema([
                TextInput::make('contact_email')->email()->required()->maxLength(255),
                TextInput::make('contact_phone')->maxLength(50),
                Textarea::make('address')->maxLength(500)->columnSpanFull(),
            ])->columns(2),
            Section::make('Plan & quota')->schema([
                Select::make('plan')->options([
                    'free' => 'Free',
                    'pro' => 'Pro',
                    'enterprise' => 'Enterprise',
                ])->required()->default('free'),
                TextInput::make('storage_quota_bytes')->numeric()->required()->default(536870912)
                    ->helperText('Storage quota in bytes (default 512 MB).'),
                TextInput::make('owner_user_id')->label('Owner user ID')->numeric(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('court_type')->label('Court type')->badge()->sortable(),
                TextColumn::make('plan')->badge()->sortable(),
                TextColumn::make('users_count')->counts('users')->label('Users')->sortable(),
                TextColumn::make('storage_usage')
                    ->label('Storage')
                    ->state(fn (Organization $record): string => number_format($record->storageUsagePercent(), 1).'%')
                    ->sortable(false),
                IconColumn::make('is_suspended')->label('Suspended')->boolean(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('plan')->options([
                    'free' => 'Free',
                    'pro' => 'Pro',
                    'enterprise' => 'Enterprise',
                ]),
                SelectFilter::make('court_type')->options([
                    'High Court' => 'High Court',
                    'Magistrate Court' => 'Magistrate Court',
                    'Customary Court of Appeal' => 'Customary Court of Appeal',
                    'Tribunal' => 'Tribunal',
                    'Law Firm' => 'Law Firm',
                ]),
                TernaryFilter::make('is_suspended')->label('Suspended'),
            ])
            ->actions([
                Action::make('toggleSuspension')
                    ->label(fn (Organization $record): string => $record->is_suspended ? 'Reactivate' : 'Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Organization $record) => $record->update(['is_suspended' => ! $record->is_suspended])),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
