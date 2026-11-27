<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who a post can go to: district staff (by district), the leaders who get the reports, and — only when ticked — the
 * citizens. Only people the bot can reach: in the bot (users), Telegram id known, not blocked.
 */
class PostAudience
{
    public const STAFF = 'staff';

    public const LEADERS = 'leaders';

    public const CITIZENS = 'citizens';

    /** ticked by default */
    public const GROUPS = [
        self::STAFF => 'Tuman xodimlari',
        self::LEADERS => 'Rahbarlar (hisobot oluvchilar)',
    ];

    /** a separate box, not ticked by default */
    public const CITIZENS_LABEL = 'Aholi (botdagi fuqarolar)';

    public static function groups(): array
    {
        return self::GROUPS + [self::CITIZENS => self::CITIZENS_LABEL];
    }

    /**
     * @param  array<string>  $groups
     * @param  array<int>  $districts  empty: every district (citizens and leaders have none: never narrowed by it)
     */
    public static function recipients(array $groups, array $districts = []): Builder
    {
        $staff = fn ($q) => $q->select('user_id')->from('staff')->whereNotNull('user_id')->where('is_active', true)
            ->when($districts, fn ($q) => $q->whereIn('district_id', $districts));
        $anyStaff = fn ($q) => $q->select('user_id')->from('staff')->whereNotNull('user_id');
        $leaders = fn ($q) => $q->select('user_id')->from('report_recipients');

        return DB::connection('bot')->table('users')->whereNull('blocked_at')->whereNotNull('user_id')
            ->where(function ($q) use ($groups, $staff, $anyStaff, $leaders) {
                $any = false;
                if (in_array(self::STAFF, $groups, true)) {
                    $q->orWhereIn('user_id', $staff);
                    $any = true;
                }
                if (in_array(self::LEADERS, $groups, true)) {
                    $q->orWhereIn('user_id', $leaders);
                    $any = true;
                }
                if (in_array(self::CITIZENS, $groups, true)) {
                    $q->orWhere(fn ($w) => $w->whereNotIn('user_id', $anyStaff)->whereNotIn('user_id', $leaders));
                    $any = true;
                }
                $any || $q->whereRaw('1 = 0');
            });
    }

    /** @return array<string, string> Telegram id => "F.I.Sh · bo'lim · tuman", by name */
    public static function options(array $groups, array $districts = []): array
    {
        $db = DB::connection('bot');
        $users = self::recipients($groups, array_map('intval', $districts))
            ->get(['user_id', 'full_name', 'first_name', 'last_name', 'phone']);
        $ids = $users->pluck('user_id')->all();
        $staff = $ids ? $db->table('staff as s')->leftJoin('districts as d', 'd.id', '=', 's.district_id')
            ->whereIn('s.user_id', $ids)->get(['s.user_id', 's.full_name', 'd.name as district'])->keyBy('user_id') : collect();
        $leaders = $ids ? $db->table('report_recipients')->whereIn('user_id', $ids)->pluck('label', 'user_id') : collect();

        return $users->map(function ($u) use ($staff, $leaders) {
            $s = $staff[$u->user_id] ?? null;
            $name = $s->full_name ?? (trim((string) $u->full_name) ?: (trim($u->first_name.' '.$u->last_name) ?: ($u->phone ?: (string) $u->user_id)));
            $parts = $s ? [$name, 'xodim', $s->district ? trim(str_replace(['тумани', 'tumani'], '', $s->district)) : 'tumanga biriktirilmagan']
                : ($leaders->has($u->user_id) ? [$name, 'rahbar'] : [$name, 'aholi']);

            return ['id' => (string) $u->user_id, 'label' => implode(' · ', array_filter($parts))];
        })->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->pluck('label', 'id')->all();
    }
}
