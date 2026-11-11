<?php

namespace App\Support;

/** Uzbek Cyrillic -> Latin (the bot's i18n.latin rules), and a comparison key for street names in either script. */
class Latin
{
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g‘', 'д' => 'd', 'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
        'қ' => 'q', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ў' => 'o‘',
        'ф' => 'f', 'х' => 'x', 'ҳ' => 'h', 'ц' => 's', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '’', 'ы' => 'i', 'ь' => '', 'э' => 'e',
        'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya',
    ];

    private const VOWELS = 'аеёиоуўэюяaeiou';

    /** words that say what kind of street it is, not which one */
    private const GENERIC = ['kochasi', 'kocha', 'kuchasi', 'shoh', 'yoli', 'tumani', 'kochalari', 'mavzesi', 'tor', 'berk',
        'ulitsa', 'ulitsasi', 'ulisa', 'ulisasi', 'prospekt', 'prospekti', 'shosse', 'shossesi', 'proyezd', 'proezd', 'pereulok', 'street', 'avenue', 'mahallasi'];

    public static function of(string $s): string
    {
        $chars = mb_str_split($s);
        $out = '';
        foreach ($chars as $i => $ch) {
            $low = mb_strtolower($ch);
            if ($low === 'е') {
                $prev = $i ? mb_strtolower($chars[$i - 1]) : ' ';
                $r = (! preg_match('/\p{L}/u', $prev) || mb_strpos(self::VOWELS.'ъь', $prev) !== false) ? 'ye' : 'e';
            } else {
                $r = self::MAP[$low] ?? null;
            }
            if ($r === null) {
                $out .= $ch;

                continue;
            }
            $out .= $ch !== $low && $r !== '' ? mb_strtoupper(mb_substr($r, 0, 1)).mb_substr($r, 1) : $r;
        }

        return $out;
    }

    /** Two name words are the same word: equal, or (long words) at most 2 letters apart (Ахангаран / Ohangaron). */
    public static function same(string $a, string $b): bool
    {
        return $a === $b || (min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 2);
    }

    /** @return list<string> the distinctive words of a street name, Latin, lower case, no apostrophes */
    public static function words(string $name): array
    {
        $k = mb_strtolower(self::of($name));
        $k = str_replace(['’', "'", '‘', '`', 'ʻ', 'ʼ'], '', $k);
        $k = str_replace(['kh', 'x'], 'h', $k);  // х / ҳ / kh are spelt every way in street names
        $k = preg_replace('/[^a-z0-9 ]+/u', ' ', $k);

        return array_values(array_filter(explode(' ', $k), fn ($w) => $w !== '' && ! in_array($w, self::GENERIC, true) && (strlen($w) > 1 || ctype_digit($w))));
    }
}
