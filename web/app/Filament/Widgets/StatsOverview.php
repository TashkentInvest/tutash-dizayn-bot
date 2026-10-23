<?php

namespace App\Filament\Widgets;

use App\Services\BotStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $t = BotStats::totals();

        return [
            Stat::make('Foydalanuvchilar', $t['users'])->description("Bugun +{$t['new_today']}"),
            Stat::make('Ro‘yxatdan o‘tganlar', $t['registered'])->description("Bugun +{$t['registered_today']}"),
            Stat::make('Yuklab olishlar', $t['downloads'])->description("Bugun +{$t['downloads_today']}"),
        ];
    }
}
