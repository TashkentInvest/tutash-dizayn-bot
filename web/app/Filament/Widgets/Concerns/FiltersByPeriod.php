<?php

namespace App\Filament\Widgets\Concerns;

use Carbon\CarbonInterface;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/** "Davr" filter for download rankings: today / 7 days / 30 days / all time, days counted in the display timezone. */
trait FiltersByPeriod
{
    public const PERIODS = ['today' => 'Bugun', '7' => 'Oxirgi 7 kun', '30' => 'Oxirgi 30 kun'];

    protected static function periodFilter(): SelectFilter
    {
        return SelectFilter::make('period')->label('Davr')->options(self::PERIODS)->placeholder('Butun davr')
            ->query(fn (Builder $query, array $data) => self::sinceScope($query, $data['value'] ?? null));
    }

    protected static function sinceScope(Builder $query, ?string $period): Builder
    {
        $since = self::since($period);

        return $since ? $query->where('sent_at', '>=', $since->utc()->format('Y-m-d H:i:s')) : $query;
    }

    protected static function since(?string $period): ?CarbonInterface
    {
        $today = now(config('app.display_timezone'))->startOfDay();

        return match ($period) {
            'today' => $today,
            '7' => $today->subDays(6),
            '30' => $today->subDays(29),
            default => null,
        };
    }
}
