<?php

namespace App\Filament\Resources\Districts;

use App\Filament\Resources\Districts\Pages\CreateDistrict;
use App\Filament\Resources\Districts\Pages\EditDistrict;
use App\Filament\Resources\Districts\Pages\ListDistricts;
use App\Filament\Resources\Districts\RelationManagers\StreetsRelationManager;
use App\Models\District;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class DistrictResource extends Resource
{
    protected static ?string $model = District::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?string $modelLabel = 'tuman';

    protected static ?string $pluralModelLabel = 'Tumanlar';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Tuman nomi')->required()->maxLength(255),
            TextInput::make('sort_order')->label('Tartib raqami')->numeric()->default(0)
                ->helperText('Botda tumanlar shu raqam bo‘yicha tartiblanadi'),
            TextInput::make('phone')->label('Murojaat telefoni')->tel()->placeholder('+998 99 201 22 72')
                ->helperText('Tuman ochilganda botda “savolingiz bo‘lsa shu raqamga murojaat qiling” deb ko‘rsatiladi. Bo‘sh qoldirsangiz, ko‘rsatilmaydi.')
                ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                    if (filled($value) && Phone::normalize($value) === null) {
                        $fail('Raqam noto‘g‘ri. Namuna: +998 99 201 22 72');
                    }
                }])
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Phone::normalize($state) : null),
            Toggle::make('is_active')->label('Botda ko‘rinsin')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['streets', 'files']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label('Tuman')->searchable(),
                TextColumn::make('phone')->label('Murojaat telefoni')->placeholder('—')
                    ->formatStateUsing(fn ($state) => Phone::pretty($state)),
                TextColumn::make('streets_count')->label('Ko‘chalar'),
                TextColumn::make('files_count')->label('Fayllar'),
                ToggleColumn::make('is_active')->label('Botda ko‘rinadi'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Tuman, uning barcha ko‘chalari va fayllari o‘chiriladi. Fayllar o‘chirilmaydi, data/_trash papkasiga ko‘chiriladi.'),
            ]);
    }

    public static function getRelations(): array
    {
        return [StreetsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDistricts::route('/'),
            'create' => CreateDistrict::route('/create'),
            'edit' => EditDistrict::route('/{record}/edit'),
        ];
    }
}
