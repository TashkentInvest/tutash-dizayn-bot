<?php

namespace App\Filament\Widgets;

use App\Models\Download;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TopStreets extends TableWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Eng ko‘p yuklab olingan ko‘chalar';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Download::query()
                    ->selectRaw('MIN(id) as id, district, street, COUNT(*) as total')
                    ->groupBy('district', 'street')
            )
            ->defaultSort('total', 'desc')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('district')->label('Tuman'),
                TextColumn::make('street')->label('Ko‘cha'),
                TextColumn::make('total')->label('Yuklab olish')->sortable(),
            ]);
    }
}
