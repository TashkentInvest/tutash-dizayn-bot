<?php

namespace App\Filament\Resources\Downloads;

use App\Filament\Resources\Downloads\Pages\ListDownloads;
use App\Models\Download;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only log of every file/link the bot delivered. */
class DownloadResource extends Resource
{
    protected static ?string $model = Download::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $modelLabel = 'yuklab olish';

    protected static ?string $pluralModelLabel = 'Yuklab olishlar';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('sent_at', 'desc')
            ->columns([
                TextColumn::make('sent_at')->label('Vaqt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('user.full_name')->label('Foydalanuvchi')
                    ->placeholder('—')
                    ->description(fn ($record) => $record->user?->phone)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'user',
                        fn ($u) => $u->where('full_name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%")->orWhere('username', 'like', "%$search%")
                    )),
                TextColumn::make('district')->label('Tuman')->searchable()->sortable(),
                TextColumn::make('street')->label('Ko‘cha')->searchable()->sortable(),
                TextColumn::make('file_name')->label('Fayl')->limit(45)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('district')->label('Tuman')->multiple()
                    ->options(fn () => Download::query()->distinct()->orderBy('district')->pluck('district', 'district')->all()),
                SelectFilter::make('street')->label('Ko‘cha')->multiple()->searchable()
                    ->options(fn () => Download::query()->distinct()->orderBy('street')->pluck('street', 'street')->all()),
                Filter::make('sent_at')->label('Sana')
                    ->schema([
                        DatePicker::make('from')->label('Sana: dan'),
                        DatePicker::make('until')->label('Sana: gacha'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('sent_at', '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('sent_at', '<=', Carbon::parse($d, $tz)->endOfDay()->utc()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDownloads::route('/')];
    }
}
