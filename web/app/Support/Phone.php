<?php

namespace App\Support;

/** Uzbek mobile numbers: "99-201-22-72", "+998 (99) 201 22 72", "992012272" -> "+998992012272". */
class Phone
{
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return strlen($digits) === 12 && str_starts_with($digits, '998') ? '+'.$digits : null;
    }

    /** "+998992012272" -> "+998 99 201 22 72" (what the bot shows); unknown shapes are returned as they are. */
    public static function pretty(?string $stored): string
    {
        $n = self::normalize($stored);

        return $n ? sprintf('+998 %s %s %s %s', substr($n, 4, 2), substr($n, 6, 3), substr($n, 9, 2), substr($n, 11, 2)) : (string) $stored;
    }
}
