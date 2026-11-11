<?php

namespace App\Services;

use App\Models\ReportRecipient;
use App\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The reports the bot sends on its own (who gets which: Sozlamalar -> Hisobot oluvchilar):
 *   hourly      09:00-18:00, a short status text
 *   daily       18:00, the svod and bajarilish pictures of the day that just ended
 *   attendance  18:00, every active staff member: when they worked in the Mini App, what they entered
 * Messages go to the outbox; the bot delivers them.
 */
class ScheduledReports
{
    public const TYPES = ['hourly' => 'Soatlik holat', 'daily' => 'Kunlik svod (rasmlar)', 'attendance' => 'Kunlik davomat'];

    private const COLUMN = ['hourly' => 'hourly_report', 'daily' => 'daily_report', 'attendance' => 'attendance_report'];

    /** @return list<int> Telegram ids that get this report */
    public static function recipients(string $type): array
    {
        return ReportRecipient::query()->where(self::COLUMN[$type], true)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Sends one report type now; returns the number of messages queued. */
    public static function send(string $type, ?CarbonInterface $at = null, ?array $to = null): int
    {
        $at ??= now();
        $to ??= self::recipients($type);
        if (! $to) {
            return 0;
        }
        // at 18:00 the report day has just turned: the day that ended is the one a minute ago
        $day = DesignCodeSvod::reportDay($at->copy()->subMinutes(5));

        return match ($type) {
            'daily' => SvodReport::send(array_keys(SvodImage::KINDS), $to, $day),
            'hourly' => self::queue($to, self::hourlyText($at)),
            'attendance' => self::queue($to, self::attendanceText($day)),
        };
    }

    public static function hourlyText(CarbonInterface $at): string
    {
        $day = DesignCodeSvod::reportDay($at);
        $t = DesignCodeSvod::byDistrict($day)['total'];
        $staff = Staff::query()->where('is_active', true)->count();
        $working = DB::connection('bot')->table('staff_days')->where('day', $at->copy()->setTimezone(config('app.display_timezone'))->toDateString())->count();

        return '📊 Dizayn-kod · soat '.$at->copy()->setTimezone(config('app.display_timezone'))->format('H:i')." holati\n"
            ."Bugun: yechildi {$t['day_removed']}, qo‘yildi {$t['day_installed']}\n"
            ."Jarayonda (tasdiq kutmoqda): {$t['review']}\n"
            ."Ishlayotgan xodimlar: $working / $staff\n"
            ."Umumiy: qo‘yildi {$t['installed']} / {$t['total']} (".DesignCodeSvod::pct($t['pct']).')';
    }

    /** Every active staff member, by district: worked or not, from when to when, what they entered (the report day). */
    public static function attendanceText(CarbonInterface $day): string
    {
        $tz = config('app.display_timezone');
        [$from, $to] = DesignCodeSvod::window($day);
        $db = DB::connection('bot');
        $days = $db->table('staff_days')->whereIn('day', [$day->copy()->subDay()->toDateString(), $day->toDateString()])
            ->get()->groupBy('staff_id');
        $objects = $db->table('objects')->whereBetween('created_at', [$from, $to])->selectRaw('created_by, COUNT(*) AS n')
            ->groupBy('created_by')->pluck('n', 'created_by');
        $evidence = $db->table('inspections')->whereNotNull('submitted_at')->where('submitted_at', '>=', $from)->where('submitted_at', '<', $to)
            ->selectRaw('staff_id, COUNT(*) AS n')->groupBy('staff_id')->pluck('n', 'staff_id');
        $staff = Staff::query()->where('is_active', true)->with('district')->get()
            ->sortBy(fn ($s) => [$s->district?->sort_order ?? 0, $s->full_name]);

        $lines = [];
        $worked = 0;
        foreach ($staff as $s) {
            // the report day runs 18:00 -> 18:00: keep the visits inside that window
            $visits = ($days[$s->id] ?? collect())->filter(fn ($d) => $d->last_at >= $from && $d->first_at < $to);
            $o = (int) ($objects[$s->id] ?? 0);
            $e = (int) ($evidence[$s->id] ?? 0);
            $who = $s->full_name.' ('.str_replace(' тумани', '', (string) $s->district?->name).')';
            if ($visits->isEmpty() && ! $o && ! $e) {
                $lines[] = "❌ {$who} — faollik yo‘q".($s->user_id ? '' : ' (botga ulanmagan)');

                continue;
            }
            $worked++;
            $first = \Carbon\Carbon::parse($visits->min('first_at') ?? $from, 'UTC')->setTimezone($tz)->format('H:i');
            $last = \Carbon\Carbon::parse($visits->max('last_at') ?? $to, 'UTC')->setTimezone($tz)->format('H:i');
            $lines[] = "✅ {$who} — {$first}–{$last} · obyekt: {$o} · dalil: {$e}";
        }

        return '🧑‍💼 Xodimlar davomati — '.$day->format('d.m.Y')." (kecha 18:00 dan bugun 18:00 gacha)\n"
            ."Ishlagan: $worked / ".$staff->count()."\n\n".implode("\n", $lines);
    }

    private static function queue(array $to, string $text): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        // Telegram allows 4096 characters: a long attendance list goes as several messages
        $parts = [];
        $chunk = '';
        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($chunk) + mb_strlen($line) + 1 > 3800) {
                $parts[] = $chunk;
                $chunk = '';
            }
            $chunk .= ($chunk === '' ? '' : "\n").$line;
        }
        $parts[] = $chunk;
        $rows = [];
        foreach ($to as $uid) {
            foreach ($parts as $p) {
                $rows[] = ['user_id' => $uid, 'text' => $p, 'created_at' => $now];
            }
        }
        DB::connection('bot')->table('outbox')->insert($rows);

        return count($rows);
    }
}
