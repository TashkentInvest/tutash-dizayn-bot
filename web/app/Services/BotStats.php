<?php

namespace App\Services;

use App\Models\BotUser;
use App\Models\Download;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/**
 * All dashboard numbers live here, independent of Filament, so any other UI (or an API) can reuse them.
 * Day grouping is done in PHP in the display timezone, so it behaves the same on SQLite and PostgreSQL.
 */
class BotStats
{
    public static function tz(): string
    {
        return config('app.display_timezone', 'Asia/Tashkent');
    }

    /** @return array{users:int, registered:int, new_today:int, registered_today:int, downloads:int, downloads_today:int} */
    public static function totals(): array
    {
        return Cache::remember('bot.stats.totals', 30, fn () => self::computeTotals());
    }

    private static function computeTotals(): array
    {
        $todayStartUtc = CarbonImmutable::now(self::tz())->startOfDay()->utc();

        return [
            'users' => BotUser::count(),
            'registered' => BotUser::whereNotNull('registered_at')->count(),
            'new_today' => BotUser::where('first_seen', '>=', $todayStartUtc)->count(),
            'registered_today' => BotUser::where('registered_at', '>=', $todayStartUtc)->count(),
            'downloads' => Download::count(),
            'downloads_today' => Download::where('sent_at', '>=', $todayStartUtc)->count(),
        ];
    }

    /** @return Collection<string,int> 'Y-m-d' => count for each of the last $days days (zeros included) */
    public static function downloadsPerDay(int $days = 30): Collection
    {
        return self::perDay(Download::query(), 'sent_at', $days);
    }

    public static function registrationsPerDay(int $days = 30): Collection
    {
        return self::perDay(BotUser::whereNotNull('registered_at'), 'registered_at', $days);
    }

    /** @return Collection<int,object{district:string,street:string,total:int}> */
    public static function topStreets(int $limit = 10): Collection
    {
        return Download::query()
            ->selectRaw('district, street, COUNT(*) as total')
            ->groupBy('district', 'street')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<string,int> district => downloads */
    public static function downloadsByDistrict(): Collection
    {
        return Download::query()
            ->selectRaw('district, COUNT(*) as total')
            ->groupBy('district')
            ->orderByDesc('total')
            ->pluck('total', 'district');
    }

    /**
     * Counts per local day, grouped inside the database (not in PHP): constant memory however many rows there are.
     * The day boundary is the display timezone's, so a download at 23:30 UTC counts for the next Tashkent day.
     */
    private static function perDay($query, string $column, int $days): Collection
    {
        $tz = self::tz();
        $from = CarbonImmutable::now($tz)->startOfDay()->subDays($days - 1);

        // Cache a plain array: Laravel 13 refuses to unserialize objects (a cached Collection comes back as
        // __PHP_Incomplete_Class and the dashboard fails with a 500 on every cache hit).
        $perDay = Cache::remember("bot.stats.perday.$column.$days", 60, function () use ($query, $column, $days, $tz, $from): array {
            $day = match ($query->getConnection()->getDriverName()) {
                'pgsql' => "to_char($column AT TIME ZONE '$tz', 'YYYY-MM-DD')",
                default => "strftime('%Y-%m-%d', $column, '".sprintf('%+d', $from->getOffset() / 3600)." hours')",
            };
            $counts = $query->where($column, '>=', $from->utc())
                ->selectRaw("$day as day, COUNT(*) as n")->groupBy('day')->pluck('n', 'day');

            return collect(range(0, $days - 1))
                ->mapWithKeys(fn ($i) => [$from->addDays($i)->format('Y-m-d') => (int) $counts->get($from->addDays($i)->format('Y-m-d'), 0)])
                ->all();
        });

        return collect($perDay);
    }
}
