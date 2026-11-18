<?php

namespace App\Services;

use App\Models\DesignObject;
use App\Models\Inspection;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Every morning (09:00): each staff member with objects "Jarayonda" (accepted with something to fix) is told how many and
 * what to fix, through the bot (outbox).
 */
class ProcessReminder
{
    private const LIST_MAX = 25;

    public static function send(): int
    {
        $n = 0;
        foreach (Staff::query()->where('is_active', true)->whereNotNull('user_id')->get() as $staff) {
            $text = self::text($staff);
            if ($text !== null) {
                DB::connection('bot')->table('outbox')->insert(['user_id' => $staff->user_id, 'text' => $text,
                    'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
                $n++;
            }
        }

        return $n;
    }

    public static function text(Staff $staff): ?string
    {
        $objects = DesignObject::query()->active()->whereIn('street_id', $staff->streetsQuery()->pluck('id'))
            ->whereHas('processInspection')->with(['processInspection', 'street:id,name'])->orderBy('street_id')->orderBy('shop_name')->get();
        if ($objects->isEmpty()) {
            return null;
        }
        $ru = DB::connection('bot')->table('users')->where('user_id', $staff->user_id)->value('lang') === 'ru';
        $lines = $objects->take(self::LIST_MAX)->map(fn (DesignObject $o) => '• '.$o->shop_name.' — '.$o->street?->name
            .(($w = self::what($o->processInspection, $ru)) ? ': '.$w : ''));
        $more = $objects->count() > self::LIST_MAX ? "\n… +".($objects->count() - self::LIST_MAX) : '';

        return $ru
            ? "🔄 Доброе утро! У вас в процессе: {$objects->count()} объект(ов).\n\n".$lines->implode("\n").$more
                ."\n\nУстраните недочёты и отправьте снова: кнопка «🔄 В процессе» в боте."
            : "🔄 Xayrli tong! Sizda jarayonda: {$objects->count()} ta obyekt.\n\n".$lines->implode("\n").$more
                ."\n\nMuammolarini bartaraf qilib, qayta jo‘nating: botdagi «🔄 Jarayondagilar» tugmasi.";
    }

    private static function what(?Inspection $i, bool $ru): string
    {
        [$keys, $note] = InspectionReview::readReasons($i?->review_note);
        $words = array_map(fn ($k) => Inspection::PROCESS_REASONS[$k][$ru ? 1 : 0], $keys);

        return implode(', ', array_filter([...$words, $note]));
    }
}
