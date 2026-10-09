<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use App\Services\ArchiveAccess;
use App\Services\ArchiveWorkflow;
use App\Services\TenantContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\URL;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Documents';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(SoftDeletingScope::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Document metadata')->schema([
                TextInput::make('friendly_name')->label('Document name')->required()->maxLength(500),
                TextInput::make('original_filename')->label('Original filename')->readOnly()->dehydrated(),
                TextInput::make('folio_number')->label('Folio number')->maxLength(200),
                Textarea::make('description')->label('Description')->helperText('Add the context needed to identify this document.')->columnSpanFull(),
                Select::make('case_id')->relationship('case', 'title')->searchable(),
                Select::make('proceeding_id')->relationship('proceeding', 'presiding_judge')->searchable(),
                Select::make('judicial_document_type')->options(['judgment' => 'Judgment', 'ruling' => 'Ruling', 'order' => 'Order', 'case_file' => 'Case file', 'transcript' => 'Transcript'])->required(),
                DatePicker::make('date_delivered'),
                DatePicker::make('retention_date')->label('Retention review date'),
            ])->columns(2),
            Section::make('File')->schema([
                FileUpload::make('storage_path')
                    ->label('Document file')
                    ->disk('s3')
                    ->directory(fn () => 'org_'.app(TenantContext::class)->id().'/incoming/user_'.auth()->id())
                    ->storeFileNamesIn('original_filename')
                    ->visibility('private')
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'text/plain',
                        'text/csv',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->maxSize(262144)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('friendly_name')->searchable()->sortable()->wrap(),
                TextColumn::make('original_filename')->label('Filename')->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('mime_type')->label('Type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size_bytes')->label('Size')->numeric()->sortable(),
                TextColumn::make('retention_date')->date()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('judicial_document_type')->options(['judgment' => 'Judgment', 'ruling' => 'Ruling', 'order' => 'Order', 'case_file' => 'Case file', 'transcript' => 'Transcript']),
                SelectFilter::make('case')->relationship('case', 'title'),
                Filter::make('delivery_dates')->form([DatePicker::make('from'), DatePicker::make('until')])->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date_delivered', '>=', $d))->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('date_delivered', '<=', $d))),
                SelectFilter::make('status')->options([
                    'uploading' => 'Uploading',
                    'quarantined' => 'Quarantined',
                    'draft' => 'Draft',
                    'published' => 'Published',
                    'archived' => 'Archived',
                    'pending_review' => 'Pending review',
                    'deleted' => 'Deleted',
                ]),
                TrashedFilter::make(),
            ])
            ->actions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Document $record): string => URL::temporarySignedRoute(
                        'documents.preview',
                        now()->addMinutes(10),
                        ['document' => $record->uuid],
                    ))
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => auth()->user()->can('view', $record) && app(ArchiveAccess::class)->eligible($record)),
                Action::make('submit')->visible(fn (Document $record) => $record->status === 'draft' && auth()->user()->checkPermissionTo('document.submit'))
                    ->requiresConfirmation()->action(fn (Document $record) => app(ArchiveWorkflow::class)->transition($record, auth()->user(), 'pending_review', 'draft')),
                Action::make('publish')->visible(fn (Document $record) => $record->status === 'pending_review' && auth()->user()->checkPermissionTo('document.review'))
                    ->requiresConfirmation()->action(fn (Document $record) => app(ArchiveWorkflow::class)->transition($record, auth()->user(), 'published', 'pending_review')),
                Action::make('return_to_draft')->visible(fn (Document $record) => $record->status === 'pending_review' && auth()->user()->checkPermissionTo('document.review'))
                    ->requiresConfirmation()->action(fn (Document $record) => app(ArchiveWorkflow::class)->transition($record, auth()->user(), 'draft', 'pending_review')),
                Action::make('archive')->visible(fn (Document $record) => $record->status === 'published' && auth()->user()->checkPermissionTo('document.review'))
                    ->requiresConfirmation()->action(fn (Document $record) => app(ArchiveWorkflow::class)->transition($record, auth()->user(), 'archived', 'published')),
                RestoreAction::make()->requiresConfirmation(),
                EditAction::make(),
                DeleteAction::make()
                    ->label('Move to trash')
                    ->modalHeading('Move document to trash?')
                    ->modalDescription('The document will be hidden from active lists but retained for recovery according to your organization policy.')
                    ->requiresConfirmation(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
