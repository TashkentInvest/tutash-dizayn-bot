<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BotUsers\BotUserResource;
use App\Filament\Resources\Downloads\DownloadResource;
use App\Services\BotStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Each card opens the list it counts, already filtered the same way. */
class StatsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $t = BotStats::totals();

        return [
            Stat::make('Foydalanuvchilar', $t['users'])
                ->description("Bugun +{$t['new_today']}  ·  ro‘yxatni ochish")
                ->url(BotUserResource::getUrl('index')),
            Stat::make('Ro‘yxatdan o‘tganlar', $t['registered'])
                ->description("Bugun +{$t['registered_today']}  ·  ro‘yxatni ochish")
                ->url(BotUserResource::getUrl('index').'?'.http_build_query(['filters' => ['registered' => ['value' => 1]]])),
            Stat::make('Yuklab olishlar', $t['downloads'])
                ->description("Bugun +{$t['downloads_today']}  ·  ro‘yxatni ochish")
                ->url(DownloadResource::getUrl('index')),
        ];
    }
}
