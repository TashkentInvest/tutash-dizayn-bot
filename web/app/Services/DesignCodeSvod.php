<?php

namespace App\Services;

use App\Models\District;
use App\Models\Inspection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Design-code progress by district (or by street of one district).
 *   Jami       - the official object list (streets.planned_objects, imported from the Excel list)
 *   Yechildi / Qo'yildi - businesses whose evidence was approved (objects.status)
 *   Kutilmoqda - Jami minus both (never below 0)
 *   Tekshiruvda, and the chosen day's approved work, from the evidence (inspections).
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
            ->where(fn ($q) => $q->where('planned_objects', '>', 0)
                ->orWhereIn('id', DB::connection('bot')->table('objects')->where('is_active', true)->select('street_id')))
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $rows = $streets->map(fn ($s) => ['id' => $s->id, 'name' => $s->name] + self::counts('s.id = ?', [$s->id], $day))->all();

        return ['rows' => $rows, 'total' => self::sum($rows, 'Tuman jami')];
    }

    private static function counts(string $where, array $bind, CarbonInterface $day): array
    {
        $db = DB::connection('bot');
        $total = (int) $db->selectOne("SELECT COALESCE(SUM(s.planned_objects), 0) AS n FROM streets s WHERE $where", $bind)->n;
        $o = $db->selectOne(
            "SELECT SUM(CASE WHEN o.status='removed' THEN 1 ELSE 0 END) AS removed,
                    SUM(CASE WHEN o.status='installed' THEN 1 ELSE 0 END) AS installed
             FROM objects o JOIN streets s ON s.id = o.street_id WHERE o.is_active = TRUE AND $where", $bind);
        $from = $day->copy()->startOfDay()->utc()->format('Y-m-d H:i:s');
        $to = $day->copy()->endOfDay()->utc()->format('Y-m-d H:i:s');
        $i = $db->selectOne(
            "SELECT SUM(CASE WHEN i.review='pending' THEN 1 ELSE 0 END) AS review,
                    SUM(CASE WHEN i.review='approved' AND i.kind='removed' AND i.submitted_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS day_removed,
                    SUM(CASE WHEN i.review='approved' AND i.kind='installed' AND i.submitted_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS day_installed
             FROM inspections i JOIN objects o ON o.id = i.object_id JOIN streets s ON s.id = o.street_id
             WHERE o.is_active = TRUE AND i.review IS NOT NULL AND $where", [$from, $to, $from, $to, ...$bind]);
        $removed = (int) $o->removed;
        $installed = (int) $o->installed;

        return [
            'total' => $total, 'pending' => max($total - $removed - $installed, 0), 'removed' => $removed, 'installed' => $installed,
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
