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
        'ulitsa', 'ulitsasi', 'ulisa', 'ulisasi', 'prospekt', 'prospekti', 'shosse', 'shossesi', 'proyezd', 'proezd', 'pereulok', 'street', 'avenue', 'mahallasi', 'doroga', 'yol', 'avtomobil', 'yoli'];

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
    /** the ring roads under all their names (Uzbek, Russian, abbreviations) become one word each */
    private const RINGS = [
        'kxay' => ['kichik halqa', 'kichik xalqa', 'malaya kolsevaya', 'malaya koltsevaya', 'kxay', 'khay'],
        'txay' => ['katta halqa', 'katta xalqa', 'tashqi halqa', 'tashqi xalqa', 'kolsevaya avtomobilnaya', 'koltsevaya avtomobilnaya',
            'obyezdnaya', 'obezdnaya', 'txay', 'tkad'],
    ];

    /**
     * Two name words are the same word: equal, or (long words) at most 2 letters apart (Ахангаран / Ohangaron), or the same
     * consonants (Чапаната / Cho'ponota, Катартал / Qatortol: Russian maps spell the vowels differently).
     */
    public static function same(string $a, string $b): bool
    {
        if ($a === $b || (min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 2)) {
            return true;
        }
        $ka = self::skeleton($a);

        return strlen($ka) >= 3 && $ka === self::skeleton($b);
    }

    /** "Янги Шаҳар" and "Янгишахар": the whole names, written together, have the same consonants */
    public static function sameJoined(array $a, array $b): bool
    {
        $ka = self::skeleton(implode('', $a));

        return strlen($ka) >= 4 && $ka === self::skeleton(implode('', $b));
    }

    private static function skeleton(string $w): string
    {
        $w = str_replace(['dj', 'dzh', 'zh', 'ts', 'q', 'y'], ['j', 'j', 'j', 's', 'k', ''], $w);

        return preg_replace('/[aeiou]+/', '', $w);
    }

    /** @return list<string> the distinctive words of a street name, Latin, lower case, no apostrophes */
    public static function words(string $name): array
    {
        $k = mb_strtolower(self::of($name));
        $k = str_replace(['’', "'", '‘', '`', 'ʻ', 'ʼ', '-'], ['', '', '', '', '', '', ' '], $k);
        foreach (self::RINGS as $word => $names) {
            foreach ($names as $n) {
                $k = str_replace($n, ' '.$word.' ', $k);
            }
        }
        $k = str_replace(['kh', 'x'], 'h', $k);  // х / ҳ / kh are spelt every way in street names
        $k = preg_replace('/[^a-z0-9 ]+/u', ' ', $k);
        // Russian adjectives: "Ахангаранское шоссе" -> ahangaran
        $k = preg_replace('/(\w{4,})(skoye|skoe|skaya|skiy|skij|skii)\b/', '$1', $k);

        return array_values(array_filter(explode(' ', $k), fn ($w) => $w !== '' && ! in_array($w, self::GENERIC, true) && (strlen($w) > 1 || ctype_digit($w))));
    }
}
