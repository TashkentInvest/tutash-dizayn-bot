<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A message from the dashboard to many bot users at once. Written to the outbox; the bot sends it at Telegram's pace
 * and marks whoever blocked the bot (users.blocked_at), so they are skipped next time.
 */
class Broadcast
{
    /** Not finished: no full name, phone or consent yet (they get the missing step after /start). */
    public const INCOMPLETE = 'incomplete';

    public const ALL = 'all';

    public const AUDIENCES = [
        self::INCOMPLETE => 'F.I.Sh. yoki telefoni yo‘qlar (ro‘yxatdan to‘liq o‘tmaganlar)',
        self::ALL => 'Hamma',
    ];

    public const START_TEXT = "Assalomu alaykum! Botimizdan foydalanish uchun /start ni bosing va familiya, ism, otasining ismi "
        ."hamda telefon raqamingizni yuboring.\n\n"
        ."Здравствуйте! Чтобы пользоваться ботом, нажмите /start и укажите Ф.И.О. и номер телефона.";

    public static function audience(string $who): Builder
    {
        return DB::connection('bot')->table('users')->whereNull('blocked_at')
            ->when($who === self::INCOMPLETE, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('full_name')->orWhere('full_name', '')->orWhereNull('phone')->orWhere('phone', '')->orWhereNull('consent_at')));
    }

    /** @return int how many messages were queued (the same text still waiting for someone is not queued twice) */
    public static function send(string $who, string $text): int
    {
        $text = trim($text);
        if ($text === '' || ! isset(self::AUDIENCES[$who])) {
            return 0;
        }
        $db = DB::connection('bot');
        $now = now()->utc()->format('Y-m-d H:i:s');

        return $db->transaction(function () use ($db, $who, $text, $now) {
            $waiting = $db->table('outbox')->whereNull('sent_at')->where('text', $text)->pluck('user_id')->flip();
            $rows = self::audience($who)->orderBy('user_id')->pluck('user_id')
                ->reject(fn ($id) => $waiting->has($id))
                ->map(fn ($id) => ['user_id' => $id, 'text' => $text, 'created_at' => $now])->values();
            foreach ($rows->chunk(500) as $chunk) {
                $db->table('outbox')->insert($chunk->all());
            }

            return $rows->count();
        });
    }
}
