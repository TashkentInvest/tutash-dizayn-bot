<?php

namespace App\Services;

use App\Models\District;
use App\Models\Inspection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Design-code progress by district (or by street of one district).
 *   Jami       - the official object list (streets.planned_objects, imported from the Excel list)
 *   Jarayonda (key "removed") - businesses whose old sign is off, the design code not on yet (objects.status = removed)
 *   Qo'yildi - the design code is on: it leaves Jarayonda and Kutilmoqda gets smaller
 *   Kutilmoqda - Jami minus both (never below 0)
 *   Tasdiq kutmoqda (key "review": evidence waiting for review or held by the moderator), and the report day's approved work.
 *   Bugun yuborildi / qo'shildi - evidence the staff sent and businesses they added in the report day (approved or not).
 * A report day ends at 18:00 (like the demolition svod): from 18:00 the "Bugun" columns start counting the next day.
 */
class DesignCodeSvod
{
    public const DAY_ENDS_AT = 18;

    /** The report day a moment belongs to: after 18:00 it is already tomorrow's. */
    public static function reportDay(?CarbonInterface $at = null): CarbonInterface
    {
        $at = ($at ?? now())->copy()->setTimezone(config('app.display_timezone'));

        return ($at->hour >= self::DAY_ENDS_AT ? $at->addDay() : $at)->startOfDay();
    }

    /** [from, to) of a report day in UTC: the day before at 18:00 up to the day at 18:00. */
    public static function window(CarbonInterface $day): array
    {
        $end = Carbon::parse($day->toDateString(), config('app.display_timezone'))->setTime(self::DAY_ENDS_AT, 0);

        return [$end->copy()->subDay()->utc()->format('Y-m-d H:i:s'), $end->copy()->utc()->format('Y-m-d H:i:s')];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: array<string, mixed>}
     */
    public static function byDistrict(CarbonInterface $day): array
    {
        $c = self::counts('s.district_id', '1 = 1', [], $day);
        $rows = District::query()->orderBy('sort_order')->get(['id', 'name'])
            ->map(fn (District $d) => ['id' => $d->id, 'name' => $d->name] + self::row($c, $d->id))->all();

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
        $c = self::counts('s.id', 's.district_id = ?', [$districtId], $day);
        $rows = $streets->map(fn ($s) => ['id' => $s->id, 'name' => $s->name] + self::row($c, $s->id))->all();

        return ['rows' => $rows, 'total' => self::sum($rows, 'Tuman jami')];
    }

    /**
     * Three grouped queries for the whole table (it used to be three per row).
     *
     * @return array{0: array<int, int>, 1: array<int, object>, 2: array<int, object>}
     */
    private static function counts(string $group, string $where, array $bind, CarbonInterface $day): array
    {
        $db = DB::connection('bot');
        $total = collect($db->select("SELECT $group AS g, COALESCE(SUM(s.planned_objects), 0) AS n FROM streets s WHERE $where GROUP BY $group", $bind))
            ->mapWithKeys(fn ($r) => [(int) $r->g => (int) $r->n])->all();
        [$from, $to] = self::window($day);
        $o = collect($db->select(
            "SELECT $group AS g, SUM(CASE WHEN o.status='removed' THEN 1 ELSE 0 END) AS removed,
                    SUM(CASE WHEN o.status='installed' THEN 1 ELSE 0 END) AS installed,
                    SUM(CASE WHEN o.created_at >= ? AND o.created_at < ? THEN 1 ELSE 0 END) AS day_added
             FROM objects o JOIN streets s ON s.id = o.street_id WHERE o.is_active = TRUE AND $where GROUP BY $group", [$from, $to, ...$bind]))
            ->keyBy(fn ($r) => (int) $r->g)->all();
        $i = collect($db->select(
            "SELECT $group AS g, SUM(CASE WHEN i.review IN ('pending', 'moderation') THEN 1 ELSE 0 END) AS review,
                    SUM(CASE WHEN i.submitted_at >= ? AND i.submitted_at < ? THEN 1 ELSE 0 END) AS day_sent,
                    SUM(CASE WHEN i.review='approved' AND i.kind='removed' AND i.submitted_at >= ? AND i.submitted_at < ? THEN 1 ELSE 0 END) AS day_removed,
                    SUM(CASE WHEN i.review='approved' AND i.kind='installed' AND i.submitted_at >= ? AND i.submitted_at < ? THEN 1 ELSE 0 END) AS day_installed
             FROM inspections i JOIN objects o ON o.id = i.object_id JOIN streets s ON s.id = o.street_id
             WHERE o.is_active = TRUE AND i.review IS NOT NULL AND $where GROUP BY $group", [$from, $to, $from, $to, $from, $to, ...$bind]))
            ->keyBy(fn ($r) => (int) $r->g)->all();

        return [$total, $o, $i];
    }

    private static function row(array $c, int $id): array
    {
        [$totals, $objects, $evidence] = $c;
        $total = $totals[$id] ?? 0;
        $removed = (int) ($objects[$id]->removed ?? 0);
        $installed = (int) ($objects[$id]->installed ?? 0);
        $i = $evidence[$id] ?? null;

        return [
            'total' => $total, 'pending' => max($total - $removed - $installed, 0), 'removed' => $removed, 'installed' => $installed,
            'review' => (int) ($i->review ?? 0), 'day_removed' => (int) ($i->day_removed ?? 0), 'day_installed' => (int) ($i->day_installed ?? 0),
            'day_sent' => (int) ($i->day_sent ?? 0), 'day_added' => (int) ($objects[$id]->day_added ?? 0),
            'pct' => $total ? round($installed / $total * 100, 1) : 0.0,
        ];
    }

    private static function sum(array $rows, string $name): array
    {
        $t = ['id' => null, 'name' => $name];
        foreach (['total', 'pending', 'removed', 'installed', 'review', 'day_removed', 'day_installed', 'day_sent', 'day_added'] as $k) {
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
