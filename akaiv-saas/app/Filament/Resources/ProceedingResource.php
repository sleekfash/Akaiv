<?php

namespace App\Filament\Resources;

use App\Models\CaseProceeding;
use App\Services\ArchiveWorkflow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProceedingResource extends Resource
{
    protected static ?string $model = CaseProceeding::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Documents';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('case_id')->relationship('case', 'title')->required()->searchable(),
            Forms\Components\DatePicker::make('session_date')->required(),
            Forms\Components\TextInput::make('presiding_judge')->required()->maxLength(256),
            Forms\Components\Textarea::make('summary_notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('session_date', 'desc')->columns([
            Tables\Columns\TextColumn::make('case.title')->searchable(),
            Tables\Columns\TextColumn::make('session_date')->date()->sortable(),
            Tables\Columns\TextColumn::make('presiding_judge')->searchable(),
            Tables\Columns\TextColumn::make('status')->badge(),
        ])->filters([
            Tables\Filters\SelectFilter::make('case')->relationship('case', 'title'),
            Tables\Filters\Filter::make('sessions')->form([Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until')])->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('session_date', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('session_date', '<=', $date))),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('publish')->visible(fn ($record) => $record->status === 'draft' && auth()->user()->checkPermissionTo('document.review'))->requiresConfirmation()->action(fn ($record) => app(ArchiveWorkflow::class)->publishProceeding($record, auth()->user())),
        ])->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ProceedingResource\Pages\ListProceedings::route('/'), 'create' => ProceedingResource\Pages\CreateProceeding::route('/create'), 'edit' => ProceedingResource\Pages\EditProceeding::route('/{record}/edit')];
    }
}
