<?php

namespace App\Services;

use App\Models\BotUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Wipes what a bot user gave at registration (name, phone, consent, language, object location and photos) and unlinks
 * them from a staff record: at the next /start the bot asks for all of it again. The Telegram account itself, its
 * downloads, requests and report subscription stay (they are history, and the same person).
 */
class BotUserReset
{
    public const NOTICE = "Ma’lumotlaringiz yangilanishi kerak: /start ni bosing va ro‘yxatdan qaytadan o‘ting.\n\n"
        ."Ваши данные нужно обновить: нажмите /start и пройдите регистрацию заново.";

    /** @return string|null the staff member who was unlinked */
    public static function reset(BotUser $u, bool $notify): ?string
    {
        $db = DB::connection('bot');
        $uploads = rtrim((string) config('bot.uploads_path'), '/\\');

        return $db->transaction(function () use ($db, $u, $notify, $uploads) {
            $staff = $db->table('staff')->where('user_id', $u->user_id)->value('full_name');
            $db->table('staff')->where('user_id', $u->user_id)->update(['user_id' => null]);

            foreach ($db->table('user_photos')->where('user_id', $u->user_id)->pluck('path') as $path) {
                $file = realpath($uploads.'/'.$path);
                if ($file && str_starts_with($file, realpath($uploads) ?: "\0")) {
                    File::delete($file);
                }
            }
            $db->table('user_photos')->where('user_id', $u->user_id)->delete();

            $db->table('users')->where('user_id', $u->user_id)->update([
                'full_name' => null, 'phone' => null, 'consent_at' => null, 'lang' => null, 'registered_at' => null,
                'approved_at' => null, 'concept_at' => null, 'latitude' => null, 'longitude' => null,
                'fb_wait' => null, 'fb_wait_until' => null,
            ]);
            if ($notify && ! $u->blocked_at) {
                $db->table('outbox')->insert(['user_id' => $u->user_id, 'text' => self::NOTICE,
                    'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
            }

            return $staff;
        });
    }
}
