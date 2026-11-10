<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FiltersByPeriod;
use App\Models\Download;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Which districts' concept files citizens download most: where to start the work.
 * Design code downloads are not a district, so they are left out here (they show in "Eng ko‘p yuklab olingan ko‘chalar").
 */
class TopDistricts extends TableWidget
{
    use FiltersByPeriod;

    public const DESIGN_CODE = 'Дизайн-код';

    protected static ?int $sort = 6;

    protected static ?string $heading = 'Tumanlar reytingi (yuklab olishlar)';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Download::query()
                    ->where('district', '!=', self::DESIGN_CODE)
                    ->selectRaw('MIN(id) as id, district, COUNT(*) as total, COUNT(DISTINCT user_id) as people, COUNT(DISTINCT street) as streets')
                    ->groupBy('district')
            )
            ->defaultSort('total', 'desc')
            ->defaultKeySort(false) // grouped rows: PostgreSQL refuses an ORDER BY on a column that is not grouped
            ->paginated(false)
            ->filters([self::periodFilter()])
            ->columns([
                TextColumn::make('rank')->label('№')->rowIndex(),
                TextColumn::make('district')->label('Tuman')->weight('medium'),
                TextColumn::make('total')->label('Yuklab olish')->sortable()
                    ->description(fn ($record, $livewire) => self::share($record->total, $livewire)),
                TextColumn::make('people')->label('Fuqarolar')->sortable()->tooltip('Kamida bitta fayl yuklab olgan turli fuqarolar'),
                TextColumn::make('streets')->label('Ko‘chalar')->sortable()->tooltip('Fayli yuklab olingan turli ko‘chalar'),
            ]);
    }

    /** @var array<string, int> total district downloads per period, counted once per request */
    private static array $totals = [];

    /** "34% ulush" of all district downloads in the same period, so districts compare at a glance. */
    private static function share(int $n, $livewire): string
    {
        $period = $livewire->tableFilters['period']['value'] ?? null;
        $all = self::$totals[$period ?? 'all'] ??= self::sinceScope(
            Download::query()->where('district', '!=', self::DESIGN_CODE), $period
        )->count();

        return $all ? round($n / $all * 100).'% ulush' : '';
    }
}
