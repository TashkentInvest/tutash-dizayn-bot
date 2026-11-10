<?php

namespace App\Services;

use Carbon\CarbonInterface;
use GdImage;

/**
 * The design-code svod as a PNG for Telegram (sent by the bot to the report recipients):
 *   svod      - every column of the dashboard table, the city total first
 *   progress  - districts ranked by Bajarilish, with a bar
 * Drawn with GD at 2x, so it stays sharp when Telegram shows it full screen. DejaVu fonts (Cyrillic + Latin) ship in resources/fonts.
 */
class SvodImage
{
    public const KINDS = ['svod' => 'Tumanlar kesimida svod', 'progress' => 'Tumanlar kesimida bajarilish'];

    private const S = 2;  // scale

    private const PAD = 12;

    public static function png(string $kind, CarbonInterface $day): string
    {
        $svod = DesignCodeSvod::byDistrict($day);
        $date = $day->format('d.m.Y');
        $pct = fn ($v) => DesignCodeSvod::pct($v);

        if ($kind === 'progress') {
            $rows = collect($svod['rows'])->sortBy([['pct', 'desc'], ['installed', 'desc'], ['total', 'desc']])->values();
            $cols = [['№', 34, 'c'], ['Tuman nomi', 190, 'l'], ['Jami obyekt', 84, 'c'], ['Qo‘yildi', 76, 'c'], ['Bajarilish', 250, 'bar']];
            $body = $rows->map(fn ($r, $i) => [$i + 1, $r['name'], $r['total'], $r['installed'], [$r['pct'], $pct($r['pct'])]])->all();
            $total = ['', $svod['total']['name'], $svod['total']['total'], $svod['total']['installed'], [$svod['total']['pct'], $pct($svod['total']['pct'])]];

            return self::table('Dizayn-kod: tumanlar kesimida bajarilish — '.$date, $cols, $total, $body);
        }

        $cols = [['№', 34, 'c'], ['Tuman nomi', 190, 'l'], ['Jami obyekt', 76, 'c'], ['Kutilmoqda', 92, 'c'], ['Yechildi', 70, 'c'],
            ['Qo‘yildi', 70, 'c'], ['Bugun yechildi', 80, 'c'], ['Bugun qo‘yildi', 80, 'c'], ['Jarayonda', 90, 'c'], ['Bajarilish', 86, 'c']];
        $line = fn ($r, $n) => [$n, $r['name'], $r['total'], $r['pending'], $r['removed'], $r['installed'], $r['day_removed'], $r['day_installed'], $r['review'], $pct($r['pct'])];
        $body = array_map(fn ($r, $i) => $line($r, $i + 1), $svod['rows'], array_keys($svod['rows']));

        return self::table('Dizayn-kod: tumanlar kesimida svod — '.$date, $cols, $line($svod['total'], ''), $body);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $cols  [title, width, align c|l|bar]
     * @param  list<mixed>  $total
     * @param  list<list<mixed>>  $rows
     */
    private static function table(string $title, array $cols, array $total, array $rows): string
    {
        $s = self::S;
        $font = resource_path('fonts/DejaVuSans.ttf');
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        $tableW = array_sum(array_column($cols, 1));
        $headH = 44;
        $rowH = 26;
        $titleH = 40;
        $w = ($tableW + 2 * self::PAD) * $s;
        $h = (self::PAD + $titleH + $headH + $rowH * (count($rows) + 1) + self::PAD) * $s;

        $im = imagecreatetruecolor($w, $h);
        $c = fn (string $hex) => imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        $white = $c('ffffff');
        $ink = $c('111827');
        $grid = $c('c9ced6');
        $headBg = $c('e2efda');
        $sumBg = $c('ddebf7');
        $zebra = $c('f8fafc');
        $barBg = $c('e5e7eb');
        $barFg = $c('22c55e');
        $muted = $c('9ca3af');
        imagefill($im, 0, 0, $white);

        // title
        self::text($im, $title, $bold, 14, $w / 2, (self::PAD + 26) * $s, $ink, 'c');

        $x0 = self::PAD;
        $y = self::PAD + $titleH;
        // header (wrapped into two lines when long)
        imagefilledrectangle($im, $x0 * $s, $y * $s, ($x0 + $tableW) * $s, ($y + $headH) * $s, $headBg);
        $x = $x0;
        foreach ($cols as [$label, $cw]) {
            $lines = self::wrap($label, $bold, 10, $cw - 8);
            foreach ($lines as $i => $ln) {
                $ly = $y + $headH / 2 + ($i - (count($lines) - 1) / 2) * 14 + 5;
                self::text($im, $ln, $bold, 10, ($x + $cw / 2) * $s, $ly * $s, $ink, 'c');
            }
            $x += $cw;
        }
        $y += $headH;

        foreach (array_merge([$total], $rows) as $ri => $row) {
            $isTotal = $ri === 0;
            $bg = $isTotal ? $sumBg : ($ri % 2 === 0 ? $zebra : $white);
            imagefilledrectangle($im, $x0 * $s, $y * $s, ($x0 + $tableW) * $s, ($y + $rowH) * $s, $bg);
            $x = $x0;
            foreach ($cols as $ci => [, $cw, $align]) {
                $v = $row[$ci];
                $f = $isTotal ? $bold : $font;
                $ty = ($y + $rowH / 2 + 5) * $s;
                if ($align === 'bar') {
                    [$p, $label] = $v;
                    $bx = $x + 8;
                    $bw = $cw - 70;
                    imagefilledrectangle($im, $bx * $s, ($y + 8) * $s, ($bx + $bw) * $s, ($y + $rowH - 8) * $s, $barBg);
                    if ($p > 0) {
                        imagefilledrectangle($im, $bx * $s, ($y + 8) * $s, (int) (($bx + max(1, $bw * min($p, 100) / 100)) * $s), ($y + $rowH - 8) * $s, $barFg);
                    }
                    self::text($im, $label, $f, 10, ($x + $cw - 8) * $s, $ty, $p > 0 || $isTotal ? $ink : $muted, 'r');
                } else {
                    $str = (string) $v;
                    // a 0 is greyed out (as on the dashboard), so the numbers that matter stand out
                    $color = ! $isTotal && $ci >= 2 && in_array($str, ['0', '0%'], true) ? $muted : $ink;
                    if ($align === 'l') {
                        self::text($im, self::fit($str, $f, 10, $cw - 12), $f, 10, ($x + 6) * $s, $ty, $color, 'l');
                    } else {
                        self::text($im, $str, $f, 10, ($x + $cw / 2) * $s, $ty, $color, 'c');
                    }
                }
                $x += $cw;
            }
            $y += $rowH;
        }

        // grid
        $top = self::PAD + $titleH;
        imagesetthickness($im, 1);
        for ($ly = $top; $ly <= $y; $ly += ($ly === $top ? $headH : $rowH)) {
            imageline($im, $x0 * $s, $ly * $s, ($x0 + $tableW) * $s, $ly * $s, $grid);
        }
        $x = $x0;
        foreach (array_merge([0], array_column($cols, 1)) as $cw) {
            $x += $cw;
            imageline($im, $x * $s, $top * $s, $x * $s, $y * $s, $grid);
        }

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private static function text(GdImage $im, string $t, string $font, int $size, float $x, float $y, int $color, string $align): void
    {
        $px = $size * self::S;
        $box = imagettfbbox($px, 0, $font, $t);
        $tw = $box[2] - $box[0];
        $x = match ($align) { 'c' => $x - $tw / 2, 'r' => $x - $tw, default => $x };
        imagettftext($im, $px, 0, (int) round($x), (int) round($y), $color, $font, $t);
    }

    private static function width(string $t, string $font, int $size): float
    {
        $box = imagettfbbox($size, 0, $font, $t);

        return $box[2] - $box[0];
    }

    /** @return list<string> */
    private static function wrap(string $t, string $font, int $size, int $max): array
    {
        if (self::width($t, $font, $size) <= $max || ! str_contains($t, ' ')) {
            return [$t];
        }
        $words = explode(' ', $t);
        $half = (int) ceil(count($words) / 2);

        return [implode(' ', array_slice($words, 0, $half)), implode(' ', array_slice($words, $half))];
    }

    private static function fit(string $t, string $font, int $size, int $max): string
    {
        if (self::width($t, $font, $size) <= $max) {
            return $t;
        }
        while (mb_strlen($t) > 2 && self::width($t.'…', $font, $size) > $max) {
            $t = mb_substr($t, 0, -1);
        }

        return $t.'…';
    }
}
