<?php

namespace App\Filament\Resources\Streets\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/** Files the bot sends for this street. Upload adds one, delete moves it to data/_trash. */
class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Fayllar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')->label('Fayl')
                ->disk('bot_data')
                ->directory(fn ($livewire) => 'dashboard/'.$livewire->getOwnerRecord()->getKey())
                // stored under a random unique name (so two uploads of "x.pdf" never overwrite each other);
                // the name the admin sees and the user receives is kept in file_name
                ->storeFileNamesIn('file_name')
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(2 * 1024 * 1024) // KB = 2 GB, Telegram's limit with a local Bot API server
                ->required()
                ->helperText('Faqat PDF. Botda 50 MB gacha fayllar Telegram orqali, kattalari havola bilan beriladi.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('file_name')->label('Fayl')->searchable()->wrap(),
                TextColumn::make('size')->label('Hajmi')->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 1)),
                TextColumn::make('created_at')->label('Qo‘shilgan')->dateTime('d.m.Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()->label('Fayl yuklash')->modalWidth('lg')
                    ->mutateDataUsing(function (array $data): array {
                        $data['size'] = Storage::disk('bot_data')->size($data['path']);
                        $data['sort_order'] = ((int) $this->getOwnerRecord()->files()->max('sort_order')) + 1;
                        $data['created_at'] = now()->utc()->format('Y-m-d H:i:s');

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('Ochish')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => route('bot-file', $record), shouldOpenInNewTab: true),
                DeleteAction::make()->modalDescription('Fayl botdan olib tashlanadi va data/_trash papkasiga ko‘chiriladi.'),
            ]);
    }
}
