<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DesignCodeSvod;
use App\Filament\Resources\BotUsers\BotUserResource;
use App\Filament\Resources\DesignObjects\DesignObjectResource;
use App\Filament\Resources\Downloads\DownloadResource;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Filament\Pages\DistrictStaff;
use App\Services\BotStats;
use App\Services\DesignCodeSvod as Svod;
use App\Support\Labels;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The day at a glance: citizens' side (top row) and district staff's work (bottom row). Each card opens its list. */
class StatsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $t = BotStats::totals();
        $w = BotStats::work();
        $week = fn ($series) => $series->values()->all(); // 7-day sparkline under the number

        return [
            Stat::make('Aholi (bot foydalanuvchilari)', number_format($t['users'], 0, '', ' '))
                ->description("Bugun +{$t['new_today']} yangi · ro‘yxatdan o‘tgan {$t['registered']}")
                ->descriptionIcon('heroicon-m-user-plus')->chart($week(BotStats::newUsersPerDay(7)))->color('primary')
                ->url(BotUserResource::getUrl('index')),
            Stat::make('Yuklab olishlar', number_format($t['downloads'], 0, '', ' '))
                ->description("Bugun +{$t['downloads_today']}")
                ->descriptionIcon('heroicon-m-arrow-down-tray')->chart($week(BotStats::downloadsPerDay(7)))->color('info')
                ->url(DownloadResource::getUrl('index')),
            Stat::make('Javobsiz murojaatlar', $w['feedback_open'])
                ->description("Bugun +{$w['feedback_today']} yangi")
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')->chart($week(BotStats::feedbackPerDay(7)))
                ->color($w['feedback_open'] ? 'danger' : 'success')
                ->url(FeedbackResource::getUrl('index').'?'.http_build_query(['filters' => ['status' => ['value' => 'new']]])),
            Stat::make('Dizayn-kod bajarilishi', Svod::pct($w['pct']))
                ->description(Labels::get('status.installed')." {$w['installed']} · ".mb_strtolower(Labels::get('status.removed'))." {$w['removed']} · jami {$w['planned']}")
                ->descriptionIcon('heroicon-m-check-badge')->color('success')
                ->url(DesignCodeSvod::getUrl()),
            Stat::make('Tuman xodimlari', "{$w['staff_linked']} / {$w['staff']}")
                ->description("Botda tasdiqlangan · bugun faol {$w['staff_active_today']}")
                ->descriptionIcon('heroicon-m-identification')->color($w['staff_linked'] < $w['staff'] ? 'warning' : 'success')
                ->url(DistrictStaff::getUrl()),
            Stat::make('Kiritilgan tadbirkorlar', number_format($w['objects'], 0, '', ' '))
                ->description("Bugun +{$w['objects_today']} · ro‘yxatda {$w['planned']}")
                ->descriptionIcon('heroicon-m-building-storefront')->chart($week(BotStats::objectsPerDay(7)))->color('primary')
                ->url(DesignObjectResource::getUrl('index')),
            Stat::make('Dalillar tasdiq kutmoqda', $w['review'])
                ->description("Bugun yuborilgan: {$w['evidence_today']}")
                ->descriptionIcon('heroicon-m-clock')->chart($week(BotStats::inspectionsPerDay(7)))
                ->color($w['review'] ? 'warning' : 'success')
                ->url(InspectionResource::getUrl('index')),
            Stat::make('Ro‘yxatdan o‘tganlar', number_format($t['registered'], 0, '', ' '))
                ->description("Bugun +{$t['registered_today']}")
                ->descriptionIcon('heroicon-m-identification')->chart($week(BotStats::registrationsPerDay(7)))->color('gray')
                ->url(BotUserResource::getUrl('index').'?'.http_build_query(['filters' => ['registered' => ['value' => 1]]])),
        ];
    }
}
