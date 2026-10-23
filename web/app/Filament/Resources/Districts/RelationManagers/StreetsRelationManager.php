<?php

namespace App\Filament\Resources\Districts\RelationManagers;

use App\Filament\Resources\Streets\StreetResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Streets of a district: add, rename, reorder, hide, delete; "Fayllar" opens the street to manage its files. */
class StreetsRelationManager extends RelationManager
{
    protected static string $relationship = 'streets';

    protected static ?string $title = 'Ko‘chalar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Ko‘cha nomi')->required()->maxLength(255),
            TextInput::make('sort_order')->label('Tartib raqami')->numeric()->default(0),
            Toggle::make('is_active')->label('Botda ko‘rinsin')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('files'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label('Ko‘cha')->searchable(),
                TextColumn::make('files_count')->label('Fayllar'),
                ToggleColumn::make('is_active')->label('Botda ko‘rinadi'),
            ])
            ->headerActions([CreateAction::make()->label('Ko‘cha qo‘shish')])
            ->recordActions([
                Action::make('files')->label('Fayllar')->icon('heroicon-o-document-arrow-up')
                    ->url(fn ($record) => StreetResource::getUrl('edit', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Ko‘cha va uning fayllari o‘chiriladi. Fayllar data/_trash papkasiga ko‘chiriladi.'),
            ]);
    }
}
