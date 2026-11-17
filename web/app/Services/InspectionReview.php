<?php

namespace App\Services;

use App\Models\DesignObject;
use App\Models\Inspection;
use Illuminate\Support\Facades\DB;

/**
 * Approving or rejecting a staff member's evidence. Only an approval changes the object:
 * "removed" -> the object is in progress (unless it is already done); "installed" -> done ("Tasdiqlash"), or accepted with
 * something to fix ("Jarayonli tasdiqlash", process()): in progress, the staff member sends new evidence later.
 * The staff member is told the result through the bot (outbox table, sent by the bot).
 */
class InspectionReview
{
    private const TEXT = [
        'approved' => ["✅ «%s» (%s) bo‘yicha dalilingiz tasdiqlandi: %s.", "✅ Ваши доказательства по «%s» (%s) подтверждены: %s."],
        'rejected' => ["❌ «%s» (%s) bo‘yicha dalilingiz rad etildi: %s.\nSabab: %s\n\nMini App’da obyektni ochib, qayta yuboring.",
            "❌ Ваши доказательства по «%s» (%s) отклонены: %s.\nПричина: %s\n\nОткройте объект в Mini App и отправьте снова."],
        'process' => ["🔄 «%s» (%s) bo‘yicha dalilingiz jarayonli tasdiqlandi: obyekt jarayonda.
Nimani tuzatish kerak: %s%s

Ishni tugatgach, Mini App’da «🔄 Jarayondagilar» bo‘limidan obyektni ochib, yangi rasm, video va joylashuv yuboring. Avvalgi dalillar saqlanib qoladi.",
            "🔄 Ваши доказательства по «%s» (%s) приняты с доработкой: объект в процессе.
Что исправить: %s%s

После работы откройте объект в Mini App в разделе «🔄 В процессе» и отправьте новые фото, видео и местоположение. Прежние доказательства сохраняются."],
        'note' => ["\nAdmin izohi: %s", "\nКомментарий администратора: %s"],
        'held' => ["⏸ «%s» (%s) bo‘yicha dalilingiz moderator jarayonida: %s.\nIzoh: %s\n\nObyekt hali tugallanmagan. Moderator qaror qilguncha bu obyektga yangi dalil yuborilmaydi.",
            "⏸ Ваши доказательства по «%s» (%s) на модерации: %s.\nКомментарий: %s\n\nОбъект ещё не завершён. До решения модератора новые доказательства по нему не принимаются."],
        'ask' => ["❓ Moderator so‘rayapti: «%s» (%s, %s)\n\n%s\n\nObyekt joyi: %s\nJavobingizni rahbaringizga yuboring.",
            "❓ Вопрос модератора: «%s» (%s, %s)\n\n%s\n\nМесто объекта: %s\nОтвет передайте своему руководителю."],
        'kind' => ['removed' => ['eski peshtaxta yechildi', 'старая вывеска снята'], 'installed' => ['dizayn-kod bo‘yicha qo‘yildi', 'установлено по дизайн-коду']],
    ];

    /** @return bool false when somebody else reviewed it first */
    public static function approve(Inspection $inspection, ?string $note, ?string $by): bool
    {
        return DB::connection('bot')->transaction(function () use ($inspection, $note, $by) {
            // waiting, held by the moderator, or accepted earlier with something to fix ("Jarayonli tasdiqlash")
            $installed = Inspection::query()->whereKey($inspection->id)->value('kind') === DesignObject::INSTALLED;
            $done = Inspection::query()->whereKey($inspection->id)->whereIn('review', Inspection::LOCKED)->update([
                'review' => Inspection::APPROVED, 'reviewed_by' => $by, 'reviewed_at' => now()->utc()->format('Y-m-d H:i:s'),
                ...($note ? ['review_note' => $note] : []),
            ]);
            if (! $done) {
                return false;
            }
            if ($installed) {  // done: what was accepted with something to fix stays as part of the work, approved with it
                Inspection::query()->where('object_id', $inspection->object_id)->where('review', Inspection::CONFIRMING)->update([
                    'review' => Inspection::APPROVED, 'reviewed_by' => $by, 'reviewed_at' => now()->utc()->format('Y-m-d H:i:s'),
                ]);
            }
            $i = $inspection->fresh(['object.street', 'staff']);
            $o = $i->object;
            $status = $installed || $o->status === DesignObject::INSTALLED ? DesignObject::INSTALLED : DesignObject::REMOVED;
            $o->forceFill([
                'status' => $status,
                // the place picked on the map in the approved evidence becomes the object's place: later evidence must be
                // taken near it (falls back to where the staff member stood)
                'latitude' => $i->obj_latitude ?? $o->latitude ?? $i->latitude,
                'longitude' => $i->obj_longitude ?? $o->longitude ?? $i->longitude,
                'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
            ])->save();
            self::tell($i, 'approved', $note);

            return true;
        });
    }

    /**
     * "Jarayonli tasdiqlash": installed evidence accepted, but something is still to fix (Inspection::PROCESS_REASONS keys).
     * The object goes to "Jarayonda"; the staff member is told what to fix and finds it under "Jarayondagilar".
     *
     * @param  list<string>  $reasons
     * @return bool false when it is not waiting any more (or nothing to fix was ticked)
     */
    public static function process(Inspection $inspection, array $reasons, ?string $note, ?string $by): bool
    {
        $reasons = array_values(array_intersect(array_keys(Inspection::PROCESS_REASONS), $reasons));
        if (! $reasons) {
            return false;
        }

        return DB::connection('bot')->transaction(function () use ($inspection, $reasons, $note, $by) {
            $text = implode('; ', array_map(fn ($k) => Inspection::PROCESS_REASONS[$k][0], $reasons)).($note ? '. '.trim($note) : '');
            $done = Inspection::query()->whereKey($inspection->id)->whereIn('review', Inspection::OPEN)
                ->where('kind', DesignObject::INSTALLED)->update([
                    'review' => Inspection::CONFIRMING, 'review_note' => $text, 'reviewed_by' => $by,
                    'reviewed_at' => now()->utc()->format('Y-m-d H:i:s'),
                ]);
            if (! $done) {
                return false;
            }
            $i = $inspection->fresh(['object.street', 'staff']);
            $o = $i->object;
            $o->forceFill([
                'status' => $o->status === DesignObject::INSTALLED ? DesignObject::INSTALLED : DesignObject::REMOVED,
                'latitude' => $i->obj_latitude ?? $o->latitude ?? $i->latitude,
                'longitude' => $i->obj_longitude ?? $o->longitude ?? $i->longitude,
                'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
            ])->save();
            $uid = $i->staff?->user_id;
            if ($uid) {
                $ru = DB::connection('bot')->table('users')->where('user_id', $uid)->value('lang') === 'ru' ? 1 : 0;
                $what = implode(', ', array_map(fn ($k) => Inspection::PROCESS_REASONS[$k][$ru], $reasons));
                DB::connection('bot')->table('outbox')->insert(['user_id' => $uid, 'text' => sprintf(self::TEXT['process'][$ru],
                    $o->shop_name, (string) $o->street?->name, $what, $note ? sprintf(self::TEXT['note'][$ru], trim($note)) : '')]);
            }

            return true;
        });
    }

    /**
     * Rejects evidence that is waiting, or takes back an approval: then the object's status is worked out again from the
     * evidence that stays approved (none: not started).
     *
     * @return bool false when somebody else reviewed it first (or it is rejected already)
     */
    public static function reject(Inspection $inspection, string $reason, ?string $by): bool
    {
        return DB::connection('bot')->transaction(function () use ($inspection, $reason, $by) {
            $was = Inspection::query()->whereKey($inspection->id)->lockForUpdate()->value('review');
            if (! in_array($was, [...Inspection::LOCKED, Inspection::APPROVED], true)) {
                return false;
            }
            $done = Inspection::query()->whereKey($inspection->id)->where('review', $was)->update([
                'review' => Inspection::REJECTED, 'review_note' => $reason, 'reviewed_by' => $by,
                'reviewed_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            if (! $done) {
                return false;
            }
            $i = $inspection->fresh(['object.street', 'staff']);
            if (in_array($was, [Inspection::APPROVED, Inspection::CONFIRMING], true) && $i->object) {
                self::restatus($i->object);
            }
            self::tell($i, 'rejected', $reason);

            return true;
        });
    }

    /**
     * "Moderator jarayonida": not approved, a note why, the object is not done. It may stay so for days (the staff member
     * cannot send new evidence for it meanwhile); approved or rejected later.
     *
     * @return bool false when it is not waiting any more
     */
    public static function hold(Inspection $inspection, string $note, ?string $by): bool
    {
        return DB::connection('bot')->transaction(function () use ($inspection, $note, $by) {
            $done = Inspection::query()->whereKey($inspection->id)->where('review', Inspection::PENDING)->update([
                'review' => Inspection::MODERATION, 'review_note' => $note, 'reviewed_by' => $by,
                'reviewed_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            if ($done) {
                self::tell($inspection->fresh(['object.street', 'staff']), 'held', $note);
            }

            return (bool) $done;
        });
    }

    /** A held one, days later: the moderator asks the staff member what happened at the object's place. */
    public static function ask(Inspection $inspection, string $question): bool
    {
        $i = $inspection->fresh(['object.street.district', 'staff']);
        $uid = $i?->staff?->user_id;
        if (! $i || $i->review !== Inspection::MODERATION || ! $uid) {
            return false;
        }
        $o = $i->object;
        $lat = $o->latitude ?? $i->obj_latitude ?? $o->found_lat ?? $i->latitude;
        $lon = $o->longitude ?? $i->obj_longitude ?? $o->found_lon ?? $i->longitude;
        $ru = DB::connection('bot')->table('users')->where('user_id', $uid)->value('lang') === 'ru' ? 1 : 0;
        $place = $lat !== null ? 'https://maps.google.com/?q='.round((float) $lat, 6).','.round((float) $lon, 6) : '—';
        DB::connection('bot')->table('outbox')->insert(['user_id' => $uid, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'text' => sprintf(self::TEXT['ask'][$ru], $o->shop_name, (string) $o->street?->name, (string) $o->street?->district?->name, trim($question), $place)]);

        return true;
    }

    /**
     * The object's status from its approved evidence: installed beats removed (an installed one still waiting for the final
     * confirmation counts as removed: "Jarayonda"); the place from the latest approved one.
     */
    private static function restatus(DesignObject $o): void
    {
        $approved = Inspection::query()->where('object_id', $o->id)->whereIn('review', [Inspection::APPROVED, Inspection::CONFIRMING])
            ->orderByDesc('reviewed_at')->orderByDesc('id')->get();
        $final = $approved->where('review', Inspection::APPROVED);
        $status = $final->contains('kind', DesignObject::INSTALLED) ? DesignObject::INSTALLED
            : ($approved->isNotEmpty() ? DesignObject::REMOVED : DesignObject::PENDING);
        $last = $approved->first();
        $o->forceFill([
            'status' => $status,
            'latitude' => $last?->obj_latitude ?? $last?->latitude ?? $o->latitude,
            'longitude' => $last?->obj_longitude ?? $last?->longitude ?? $o->longitude,
            'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
        ])->save();
    }

    private static function tell(Inspection $i, string $result, ?string $note): void
    {
        $uid = $i->staff?->user_id;
        if (! $uid) {
            return;
        }
        $ru = DB::connection('bot')->table('users')->where('user_id', $uid)->value('lang') === 'ru' ? 1 : 0;
        $kind = self::TEXT['kind'][$i->kind][$ru] ?? $i->kind;
        $street = (string) $i->object?->street?->name;
        $text = match ($result) {
            'approved' => sprintf(self::TEXT[$result][$ru], $i->object->shop_name, $street, $kind).($note ? sprintf(self::TEXT['note'][$ru], $note) : ''),
            'held' => sprintf(self::TEXT['held'][$ru], $i->object->shop_name, $street, $kind, $note),
            default => sprintf(self::TEXT['rejected'][$ru], $i->object->shop_name, $street, $kind, $note),
        };
        DB::connection('bot')->table('outbox')->insert(['user_id' => $uid, 'text' => $text]);
    }
}
