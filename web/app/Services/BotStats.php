<?php

namespace App\Services;

use App\Models\BotUser;
use App\Models\DesignObject;
use App\Models\Download;
use App\Models\Feedback;
use App\Models\Inspection;
use App\Models\Staff;
use App\Models\Street;
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
        return self::perDay(Download::query(), 'sent_at', $days, 'downloads');
    }

    public static function registrationsPerDay(int $days = 30): Collection
    {
        return self::perDay(BotUser::whereNotNull('registered_at'), 'registered_at', $days, 'registered');
    }

    public static function newUsersPerDay(int $days = 30): Collection
    {
        return self::perDay(BotUser::query(), 'first_seen', $days, 'new_users');
    }

    public static function feedbackPerDay(int $days = 30): Collection
    {
        return self::perDay(Feedback::query(), 'created_at', $days, 'feedback');
    }

    /** businesses district staff entered */
    public static function objectsPerDay(int $days = 30): Collection
    {
        return self::perDay(DesignObject::query()->where('is_active', true), 'created_at', $days, 'objects');
    }

    /** evidence sent by district staff */
    public static function inspectionsPerDay(int $days = 30): Collection
    {
        return self::perDay(Inspection::query()->whereNotNull('submitted_at'), 'submitted_at', $days, 'inspections');
    }

    /**
     * Today's work besides the citizens' numbers: requests, district staff, businesses, evidence, design-code progress.
     *
     * @return array<string, int|float>
     */
    public static function work(): array
    {
        return Cache::remember('bot.stats.work', 30, function (): array {
            $today = CarbonImmutable::now(self::tz())->startOfDay()->utc();
            $planned = (int) Street::query()->sum('planned_objects');
            $installed = DesignObject::query()->where('is_active', true)->where('status', DesignObject::INSTALLED)->count();
            $linked = Staff::query()->where('is_active', true)->whereNotNull('user_id');

            return [
                'feedback_open' => Feedback::query()->whereNull('answer')->count(),
                'feedback_today' => Feedback::query()->where('created_at', '>=', $today)->count(),
                'staff' => Staff::query()->where('is_active', true)->count(),
                'staff_linked' => (clone $linked)->count(),
                'staff_active_today' => (clone $linked)->whereIn('user_id', BotUser::query()->where('last_seen', '>=', $today)->select('user_id'))->count(),
                'objects' => DesignObject::query()->where('is_active', true)->count(),
                'objects_today' => DesignObject::query()->where('is_active', true)->where('created_at', '>=', $today)->count(),
                'planned' => $planned,
                'installed' => $installed,
                'removed' => DesignObject::query()->where('is_active', true)->where('status', DesignObject::REMOVED)->count(),
                'review' => Inspection::query()->where('review', Inspection::PENDING)->count(),
                'evidence_today' => Inspection::query()->where('submitted_at', '>=', $today)->count(),
                'pct' => $planned ? round($installed / $planned * 100, 1) : 0.0,
            ];
        });
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
    private static function perDay($query, string $column, int $days, string $key): Collection
    {
        $tz = self::tz();
        $from = CarbonImmutable::now($tz)->startOfDay()->subDays($days - 1);

        // Cache a plain array: Laravel 13 refuses to unserialize objects (a cached Collection comes back as
        // __PHP_Incomplete_Class and the dashboard fails with a 500 on every cache hit).
        $perDay = Cache::remember("bot.stats.perday.$key.$days", 60, function () use ($query, $column, $days, $tz, $from): array {
            $day = match ($query->getConnection()->getDriverName()) {
                'pgsql' => "to_char(($column AT TIME ZONE 'UTC') AT TIME ZONE '$tz', 'YYYY-MM-DD')",  // stored as UTC, no zone
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
