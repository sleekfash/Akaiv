<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CaseResource\Pages;
use App\Filament\Resources\CaseResource\RelationManagers\DocumentsRelationManager;
use App\Models\CaseFile;
use App\Services\SealedCases;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CaseResource extends Resource
{
    protected static ?string $model = CaseFile::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Case identity')->schema([
                TextInput::make('case_number')->maxLength(150)->unique(ignoreRecord: true),
                TextInput::make('suit_number')->maxLength(150),
                TextInput::make('subject_matter')->maxLength(256),
                TextInput::make('title')->required()->maxLength(500)->columnSpanFull(),
                Select::make('workspace_id')->label('Workspace')
                    ->relationship('workspace', 'name')->searchable()->preload(),
                TextInput::make('court_name')->maxLength(255),
                TextInput::make('bench_judge_name')->maxLength(255),
                TextInput::make('jurisdiction')->maxLength(150),
                Select::make('status')->options([
                    'open' => 'Open',
                    'closed' => 'Closed',
                    'archived' => 'Archived',
                ])->default('open')->required(),
            ])->columns(2),
            Section::make('Parties')->schema([
                Repeater::make('parties')
                    ->schema([
                        Select::make('role')->options([
                            'Claimant' => 'Claimant',
                            'Defendant' => 'Defendant',
                            'Petitioner' => 'Petitioner',
                            'Respondent' => 'Respondent',
                            'Witness' => 'Witness',
                        ])->required(),
                        TextInput::make('name')->required()->maxLength(500),
                    ])->columns(2)->columnSpanFull(),
            ]),
            Section::make('Dates & notes')->schema([
                DatePicker::make('date_filed'),
                DatePicker::make('date_judgment'),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('suit_number')->searchable(query: fn ($query, string $search) => $query->where(fn ($q) => $q->whereRaw('LOWER(suit_number) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhere('normalized_suit_number', 'like', '%'.strtoupper(preg_replace('/\s+/u', '', $search)).'%')))->sortable(),
                TextColumn::make('subject_matter')->searchable()->sortable(),
                TextColumn::make('case_number')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('court_name')->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('date_filed')->date()->sortable(),
                TextColumn::make('documents_count')->counts('documents')->label('Documents')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'open' => 'Open',
                    'closed' => 'Closed',
                    'archived' => 'Archived',
                ]),
                SelectFilter::make('workspace')->relationship('workspace', 'name'),
                Filter::make('delivered')->form([DatePicker::make('from'), DatePicker::make('until')])->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereHas('documents', fn ($d) => $d->whereDate('date_delivered', '>=', $date)))->when($data['until'] ?? null, fn ($q, $date) => $q->whereHas('documents', fn ($d) => $d->whereDate('date_delivered', '<=', $date)))),
            ])
            ->actions([
                Action::make('seal')->label('Seal / set grants')
                    ->visible(fn ($record) => auth()->user()->checkPermissionTo('case.seal'))
                    ->form([Select::make('user_ids')->label('Explicitly authorized members')->multiple()->options(fn ($record) => $record->organization->users()->pluck('name', 'users.id')->all())->required()])
                    ->requiresConfirmation()->action(fn ($record, array $data) => app(SealedCases::class)->seal($record, auth()->user(), $data['user_ids'])),
                EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCases::route('/'),
            'create' => Pages\CreateCase::route('/create'),
            'edit' => Pages\EditCase::route('/{record}/edit'),
        ];
    }
}
