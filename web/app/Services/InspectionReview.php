<?php

namespace App\Services;

use App\Models\DesignObject;
use App\Models\Inspection;
use Illuminate\Support\Facades\DB;

/**
 * Approving or rejecting a staff member's evidence. Only an approval changes the object:
 * "removed" -> the object is in progress (unless it is already done), "installed" -> done.
 * The staff member is told the result through the bot (outbox table, sent by the bot).
 */
class InspectionReview
{
    private const TEXT = [
        'approved' => ["✅ «%s» (%s) bo‘yicha dalilingiz tasdiqlandi: %s.", "✅ Ваши доказательства по «%s» (%s) подтверждены: %s."],
        'rejected' => ["❌ «%s» (%s) bo‘yicha dalilingiz rad etildi: %s.\nSabab: %s\n\nMini App’da obyektni ochib, qayta yuboring.",
            "❌ Ваши доказательства по «%s» (%s) отклонены: %s.\nПричина: %s\n\nОткройте объект в Mini App и отправьте снова."],
        'note' => ["\nAdmin izohi: %s", "\nКомментарий администратора: %s"],
        'kind' => ['removed' => ['eski peshtaxta yechildi', 'старая вывеска снята'], 'installed' => ['dizayn-kod bo‘yicha qo‘yildi', 'установлено по дизайн-коду']],
    ];

    /** @return bool false when somebody else reviewed it first */
    public static function approve(Inspection $inspection, ?string $note, ?string $by): bool
    {
        return DB::connection('bot')->transaction(function () use ($inspection, $note, $by) {
            $done = Inspection::query()->whereKey($inspection->id)->where('review', Inspection::PENDING)->update([
                'review' => Inspection::APPROVED, 'review_note' => $note ?: null, 'reviewed_by' => $by,
                'reviewed_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            if (! $done) {
                return false;
            }
            $i = $inspection->fresh(['object.street', 'staff']);
            $o = $i->object;
            $status = $i->kind === DesignObject::INSTALLED ? DesignObject::INSTALLED
                : ($o->status === DesignObject::INSTALLED ? DesignObject::INSTALLED : DesignObject::REMOVED);
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
     * Rejects evidence that is waiting, or takes back an approval: then the object's status is worked out again from the
     * evidence that stays approved (none: not started).
     *
     * @return bool false when somebody else reviewed it first (or it is rejected already)
     */
    public static function reject(Inspection $inspection, string $reason, ?string $by): bool
    {
        return DB::connection('bot')->transaction(function () use ($inspection, $reason, $by) {
            $was = Inspection::query()->whereKey($inspection->id)->lockForUpdate()->value('review');
            if (! in_array($was, [Inspection::PENDING, Inspection::APPROVED], true)) {
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
            if ($was === Inspection::APPROVED && $i->object) {
                self::restatus($i->object);
            }
            self::tell($i, 'rejected', $reason);

            return true;
        });
    }

    /** The object's status from its approved evidence: installed beats removed; the place from the latest approved one. */
    private static function restatus(DesignObject $o): void
    {
        $approved = Inspection::query()->where('object_id', $o->id)->where('review', Inspection::APPROVED)
            ->orderByDesc('reviewed_at')->orderByDesc('id')->get();
        $status = $approved->contains('kind', DesignObject::INSTALLED) ? DesignObject::INSTALLED
            : ($approved->contains('kind', DesignObject::REMOVED) ? DesignObject::REMOVED : DesignObject::PENDING);
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
        $text = $result === 'approved'
            ? sprintf(self::TEXT['approved'][$ru], $i->object->shop_name, $street, $kind).($note ? sprintf(self::TEXT['note'][$ru], $note) : '')
            : sprintf(self::TEXT['rejected'][$ru], $i->object->shop_name, $street, $kind, $note);
        DB::connection('bot')->table('outbox')->insert(['user_id' => $uid, 'text' => $text]);
    }
}
