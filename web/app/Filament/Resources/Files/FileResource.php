<?php

namespace App\Filament\Resources\Files;

use App\Filament\Resources\Files\Pages\ListFiles;
use App\Filament\Resources\Streets\StreetResource;
use App\Models\StreetFile;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Every file of the catalog with duplicate detection:
 *  - same size in bytes (almost always the very same file, even if renamed),
 *  - same file name,
 *  - several files in one street.
 * Deleting moves the file to data/_trash.
 */
class FileResource extends Resource
{
    protected static ?string $model = StreetFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static ?string $modelLabel = 'fayl';

    protected static ?string $pluralModelLabel = 'Barcha fayllar / dublikatlar';

    protected static ?int $navigationSort = 3;

    private const SAME_SIZE = '(select count(*) from street_files f2 where f2.size = street_files.size and street_files.size > 1024)';

    private const SAME_NAME = '(select count(*) from street_files f3 where f3.file_name = street_files.file_name)';

    private const SAME_STREET = '(select count(*) from street_files f4 where f4.street_id = street_files.street_id)';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('street.district')
            ->selectRaw('street_files.*, '.self::SAME_SIZE.' as same_size, '.self::SAME_NAME.' as same_name, '.self::SAME_STREET.' as same_street');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('size', 'desc')
            ->groups([
                Group::make('size')->label('Hajm bo‘yicha')
                    ->getTitleFromRecordUsing(fn ($record) => Number::fileSize((int) $record->size, 2).' ('.number_format($record->size, 0, '.', ' ').' bayt)'),
                Group::make('file_name')->label('Nom bo‘yicha')->collapsible(),
                Group::make('street.name')->label('Ko‘cha bo‘yicha')
                    ->getTitleFromRecordUsing(fn ($record) => $record->street?->district?->name.' — '.$record->street?->name)
                    ->orderQueryUsing(fn ($query, string $direction) => $query->orderBy('street_id', $direction))
                    ->getKeyFromRecordUsing(fn ($record) => (string) $record->street_id),
            ])
            ->columns([
                TextColumn::make('file_name')->label('Fayl')->searchable()->wrap(),
                TextColumn::make('street.district.name')->label('Tuman'),
                TextColumn::make('street.name')->label('Ko‘cha'),
                TextColumn::make('size')->label('Hajmi')->sortable()
                    ->formatStateUsing(fn ($state) => Number::fileSize((int) $state, 2))
                    ->description(fn ($record) => number_format($record->size, 0, '.', ' ').' bayt'),
                TextColumn::make('same_size')->label('Bir xil hajm')->badge()
                    ->state(fn ($record) => $record->same_size > 1 ? $record->same_size.' ta' : '—')
                    ->color(fn ($record) => $record->same_size > 1 ? 'danger' : 'gray'),
                TextColumn::make('same_name')->label('Bir xil nom')->badge()
                    ->state(fn ($record) => $record->same_name > 1 ? $record->same_name.' ta' : '—')
                    ->color(fn ($record) => $record->same_name > 1 ? 'warning' : 'gray'),
                TextColumn::make('same_street')->label('Ko‘chada fayllar')->badge()
                    ->state(fn ($record) => $record->same_street.' ta')
                    ->color(fn ($record) => $record->same_street > 1 ? 'info' : 'gray')->toggleable(),
                TextColumn::make('created_at')->label('Qo‘shilgan')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                Filter::make('duplicates')->label('Dublikatlar (hajm yoki nom bir xil)')->toggle()
                    ->query(fn (Builder $query) => $query->whereRaw('('.self::SAME_SIZE.' > 1 or '.self::SAME_NAME.' > 1)')),
                Filter::make('same_size')->label('Faqat bir xil hajm')->toggle()
                    ->query(fn (Builder $query) => $query->whereRaw(self::SAME_SIZE.' > 1')),
                Filter::make('same_name')->label('Faqat bir xil nom')->toggle()
                    ->query(fn (Builder $query) => $query->whereRaw(self::SAME_NAME.' > 1')),
                Filter::make('many_in_street')->label('Bir ko‘chada bir nechta fayl')->toggle()
                    ->query(fn (Builder $query) => $query->whereRaw(self::SAME_STREET.' > 1')),
                SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => \App\Models\District::orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->whereHas('street', fn ($q) => $q->where('district_id', $data['value']))
                        : $query),
            ])
            ->recordActions([
                Action::make('open')->label('Ochish')->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => route('bot-file', $record), shouldOpenInNewTab: true),
                Action::make('street')->label('Ko‘chaga')->icon('heroicon-o-map-pin')
                    ->url(fn ($record) => StreetResource::getUrl('edit', ['record' => $record->street_id])),
                DeleteAction::make()->modalDescription('Fayl botdan olib tashlanadi va data/_trash papkasiga ko‘chiriladi.'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFiles::route('/')];
    }
}
