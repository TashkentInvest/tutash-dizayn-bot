<?php

namespace App\Filament\Resources\Streets;

use App\Filament\Resources\Streets\Pages\CreateStreet;
use App\Filament\Resources\Streets\Pages\EditStreet;
use App\Filament\Resources\Streets\Pages\ListStreets;
use App\Filament\Resources\Streets\RelationManagers\FilesRelationManager;
use App\Models\Street;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StreetResource extends Resource
{
    protected static ?string $model = Street::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?string $modelLabel = 'ko‘cha';

    protected static ?string $pluralModelLabel = 'Ko‘chalar va fayllar';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('district_id')->label('Tuman')->relationship('district', 'name')
                ->required()->searchable()->preload(),
            TextInput::make('name')->label('Ko‘cha nomi')->required()->maxLength(255),
            TextInput::make('sort_order')->label('Tartib raqami')->numeric()->default(0)
                ->helperText('Tuman ichida shu raqam bo‘yicha tartiblanadi'),
            Toggle::make('is_active')->label('Botda ko‘rinsin')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('district')->withCount('files'))
            ->defaultSort('district_id')
            ->columns([
                TextColumn::make('district.name')->label('Tuman')->sortable()->searchable(),
                TextColumn::make('name')->label('Ko‘cha')->searchable(),
                TextColumn::make('files_count')->label('Fayllar')->sortable(),
                ToggleColumn::make('is_active')->label('Botda ko‘rinadi'),
            ])
            ->filters([
                SelectFilter::make('district_id')->label('Tuman')->relationship('district', 'name'),
                SelectFilter::make('has_files')->label('Fayl holati')
                    ->options(['yes' => 'Fayli bor', 'no' => 'Fayli yo‘q'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'yes' => $query->whereHas('files'),
                        'no' => $query->whereDoesntHave('files'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Fayllar / tahrirlash'),
                DeleteAction::make()->modalDescription('Ko‘cha va uning fayllari o‘chiriladi. Fayllar data/_trash papkasiga ko‘chiriladi.'),
            ]);
    }

    public static function getRelations(): array
    {
        return [FilesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStreets::route('/'),
            'create' => CreateStreet::route('/create'),
            'edit' => EditStreet::route('/{record}/edit'),
        ];
    }
}
