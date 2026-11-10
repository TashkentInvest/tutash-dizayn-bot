<?php

namespace App\Services;

use App\Models\District;
use App\Models\Inspection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Design-code progress by district (or by street of one district): objects in all, waiting, old sign taken down
 * ("jarayonda"), design-code sign up ("qilindi"), evidence waiting for review, and what was done on the chosen day
 * (approved evidence submitted that day). Every number comes from the same `objects` rows, so all views agree.
 */
class DesignCodeSvod
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, total: array<string, mixed>}
     */
    public static function byDistrict(CarbonInterface $day): array
    {
        $rows = District::query()->orderBy('sort_order')->get(['id', 'name'])->map(function (District $d) use ($day) {
            return ['id' => $d->id, 'name' => $d->name] + self::counts('s.district_id = ?', [$d->id], $day);
        })->all();

        return ['rows' => $rows, 'total' => self::sum($rows, 'Toshkent shahar jami')];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: array<string, mixed>}
     */
    public static function byStreet(int $districtId, CarbonInterface $day): array
    {
        $streets = DB::connection('bot')->table('streets')->where('district_id', $districtId)
            ->whereIn('id', DB::connection('bot')->table('objects')->where('is_active', 1)->where('gone', 0)->select('street_id'))
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $rows = $streets->map(fn ($s) => ['id' => $s->id, 'name' => $s->name] + self::counts('o.street_id = ?', [$s->id], $day))->all();

        return ['rows' => $rows, 'total' => self::sum($rows, 'Tuman jami')];
    }

    private static function counts(string $where, array $bind, CarbonInterface $day): array
    {
        $db = DB::connection('bot');
        $o = $db->selectOne(
            "SELECT COUNT(*) AS total, SUM(o.status='pending') AS pending, SUM(o.status='removed') AS removed, SUM(o.status='installed') AS installed
             FROM objects o JOIN streets s ON s.id = o.street_id WHERE o.is_active = 1 AND o.gone = 0 AND $where", $bind);
        $from = $day->copy()->startOfDay()->utc()->format('Y-m-d H:i:s');
        $to = $day->copy()->endOfDay()->utc()->format('Y-m-d H:i:s');
        $i = $db->selectOne(
            "SELECT SUM(i.review='pending') AS review,
                    SUM(i.review='approved' AND i.kind='removed' AND i.submitted_at BETWEEN ? AND ?) AS day_removed,
                    SUM(i.review='approved' AND i.kind='installed' AND i.submitted_at BETWEEN ? AND ?) AS day_installed
             FROM inspections i JOIN objects o ON o.id = i.object_id JOIN streets s ON s.id = o.street_id
             WHERE o.is_active = 1 AND o.gone = 0 AND i.review IS NOT NULL AND $where", [$from, $to, $from, $to, ...$bind]);
        $total = (int) $o->total;
        $installed = (int) $o->installed;

        return [
            'total' => $total, 'pending' => (int) $o->pending, 'removed' => (int) $o->removed, 'installed' => $installed,
            'review' => (int) $i->review, 'day_removed' => (int) $i->day_removed, 'day_installed' => (int) $i->day_installed,
            'pct' => $total ? round($installed / $total * 100, 1) : 0.0,
        ];
    }

    private static function sum(array $rows, string $name): array
    {
        $t = ['id' => null, 'name' => $name];
        foreach (['total', 'pending', 'removed', 'installed', 'review', 'day_removed', 'day_installed'] as $k) {
            $t[$k] = array_sum(array_column($rows, $k));
        }
        $t['pct'] = $t['total'] ? round($t['installed'] / $t['total'] * 100, 1) : 0.0;

        return $t;
    }

    public static function pct(float $v): string
    {
        return str_replace('.', ',', (string) $v).'%';
    }

    /** Evidence still waiting: the number shown next to "Tekshiruvlar" in the menu, here for the page header. */
    public static function waiting(): int
    {
        return Inspection::query()->where('review', Inspection::PENDING)->count();
    }
}
