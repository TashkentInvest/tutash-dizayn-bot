<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FiltersByPeriod;
use App\Models\Download;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

class TopStreets extends TableWidget
{
    use FiltersByPeriod;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = ['md' => 1, 'lg' => 1, '2xl' => 2];

    /** A district picked in the district ranking: this table then shows that district's streets only. */
    #[On('district-picked')]
    public function pickDistrict(?string $district = null): void
    {
        $this->tableFilters['district'] = ['value' => $district];
        $this->resetPage();
    }

    private function pickedDistrict(): ?string
    {
        return $this->tableFilters['district']['value'] ?? null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => ($d = $this->pickedDistrict())
                ? $d.': ko‘chalar kesimida eng ko‘p yuklab olinganlar'
                : 'Eng ko‘p yuklab olingan ko‘chalar')
            ->headerActions([
                \Filament\Actions\Action::make('allDistricts')->label('Barcha tumanlar')->icon('heroicon-o-x-mark')->color('gray')->size('sm')
                    ->visible(fn () => $this->pickedDistrict() !== null)
                    ->action(fn () => $this->pickDistrict(null)),
            ])
            ->query(
                Download::query()
                    ->selectRaw('MIN(id) as id, district, street, COUNT(*) as total, COUNT(DISTINCT user_id) as people')
                    ->groupBy('district', 'street')
            )
            ->defaultSort('total', 'desc')
            ->defaultKeySort(false) // grouped rows: PostgreSQL refuses an ORDER BY on a column that is not grouped
            ->paginated([8, 25])->defaultPaginationPageOption(8)
            ->filters([
                self::periodFilter(),
                SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => Download::query()->distinct()->orderBy('district')->pluck('district', 'district')->all()),
            ])
            ->columns([
                TextColumn::make('rank')->label('№')->rowIndex(),
                TextColumn::make('district')->label('Tuman'),
                TextColumn::make('street')->label('Ko‘cha'),
                TextColumn::make('total')->label('Yuklab olish')->sortable(),
                TextColumn::make('people')->label('Fuqarolar')->sortable(),
            ]);
    }
}
