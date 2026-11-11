<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a Mini App request by Telegram's signed initData (header X-Telegram-Init-Data).
 * No session or cookie is involved, so these routes need no CSRF token: the signature is the protection.
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 */
class TelegramWebApp
{
    public const EXPIRED = 'Oyna eskirgan, tugmani qayta bosing.';

    public const NOT_STAFF = 'Siz tuman xodimi sifatida tasdiqlanmagansiz. Botda /start ni bosing.';

    /** The attendance report: the first and the last time a staff member used the Mini App today (written at most once a minute). */
    private static function markWorking(int $staffId): void
    {
        if (! \Illuminate\Support\Facades\Cache::add("staff-day:$staffId", 1, 60)) {
            return;
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        DB::connection('bot')->statement(
            'INSERT INTO staff_days (staff_id, day, first_at, last_at, hits) VALUES (?, ?, ?, ?, 1)
             ON CONFLICT (staff_id, day) DO UPDATE SET last_at = excluded.last_at, hits = staff_days.hits + 1',
            [$staffId, now(config('app.display_timezone'))->toDateString(), $now, $now]);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = self::verify((string) $request->header('X-Telegram-Init-Data', ''));
        if ($user === null) {
            return response()->json(['error' => self::EXPIRED], 401);
        }
        $staff = Staff::query()->with('district')->where('user_id', $user['id'])->where('is_active', true)->first();
        if (! $staff) {
            return response()->json(['error' => self::NOT_STAFF], 403);
        }
        $request->attributes->set('staff', $staff);
        self::markWorking($staff->id);
        $request->attributes->set('tg_user', $user);
        $request->attributes->set('lang', DB::connection('bot')->table('users')->where('user_id', $user['id'])->value('lang') ?: 'uz');

        return $next($request);
    }

    /**
     * Returns Telegram's user array when the initData is genuine and not older than the configured TTL, otherwise null.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $initData, ?string $token = null, ?int $now = null): ?array
    {
        $token ??= (string) config('telegram.bot_token');
        if ($initData === '' || $token === '') {
            return null;
        }
        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? null;
        if (! is_string($hash)) {
            return null;
        }
        unset($fields['hash']);
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        if (! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            return null;
        }
        $age = ($now ?? time()) - (int) ($fields['auth_date'] ?? 0);
        if ($age > (int) config('telegram.init_data_ttl') || $age < -300) {
            return null;
        }
        $user = json_decode((string) ($fields['user'] ?? ''), true);

        return is_array($user) && isset($user['id']) ? $user : null;
    }

    /** Builds a valid initData string (tests, local tries). */
    public static function sign(array $user, string $token, ?int $authDate = null): string
    {
        $fields = ['auth_date' => (string) ($authDate ?? time()), 'query_id' => 'AAH-test', 'user' => json_encode($user, JSON_UNESCAPED_UNICODE)];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
