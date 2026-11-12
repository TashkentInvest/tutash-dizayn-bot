<?php

namespace App\Support;

/**
 * The words the bot, the dashboard and the Mini App all show (statuses, the svod's columns), from shared/labels.json.
 * The bot reads the same file (bot/i18n.py), so a status is named the same everywhere and is changed in one place.
 *
 *   Labels::get('status.removed')        // "Yechildi"
 *   Labels::get('status.removed', 'ru')  // "Снято"
 *   Labels::group('review')              // ['pending' => 'Tekshiruvda', ...]
 */
final class Labels
{
    private static ?array $all = null;

    public static function all(): array
    {
        if (self::$all === null) {
            $all = json_decode(file_get_contents(base_path('../shared/labels.json')), true, 8, JSON_THROW_ON_ERROR);
            unset($all['_']);
            self::$all = $all;
        }

        return self::$all;
    }

    public static function get(string $key, string $lang = 'uz'): string
    {
        [$group, $name] = explode('.', $key, 2);

        return self::all()[$group][$name][$lang] ?? $key;
    }

    /** @return array<string, string> key => the word in that language */
    public static function group(string $group, string $lang = 'uz'): array
    {
        return array_map(fn (array $words) => $words[$lang], self::all()[$group]);
    }
}
