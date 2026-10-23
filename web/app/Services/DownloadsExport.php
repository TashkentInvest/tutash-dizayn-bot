<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the download history to an .xlsx file, streaming (constant memory however many rows there are).
 *
 * Sheet 1 "Yuklab olishlar": one row per download.  Sheet 2 "Ko'chalar bo'yicha": totals per street.
 * Every text value is written as a STRING cell on purpose: OpenSpout would turn a text that starts with "="
 * into a formula, and names/usernames come from Telegram users (a name like =HYPERLINK(...) must stay text).
 */
class DownloadsExport
{
    public const HEADERS = ['№', 'Vaqt', 'F.I.SH.', 'Telefon', 'Username', 'Telegram ID', 'Tuman', 'Ko‘cha', 'Fayl'];

    /** @param Builder $query a Download query; users are loaded in chunks. Returns the number of exported rows. */
    public static function write(Builder $query, string $path): int
    {
        $tz = config('app.display_timezone', 'Asia/Tashkent');
        $total = (clone $query)->reorder()->count();

        $writer = new Writer();
        $writer->openToFile($path);

        $bold = (new Style())->setFontBold();
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Yuklab olishlar');
        $sheet->setSheetView((new SheetView())->setFreezeRow(2));
        $sheet->setColumnWidth(6, 1);
        $sheet->setColumnWidth(19, 2);
        $sheet->setColumnWidth(30, 3);
        $sheet->setColumnWidth(17, 4);
        $sheet->setColumnWidth(18, 5);
        $sheet->setColumnWidth(14, 6);
        $sheet->setColumnWidth(24, 7);
        $sheet->setColumnWidth(30, 8);
        $sheet->setColumnWidth(60, 9);
        if ($total > 0) {
            $sheet->setAutoFilter(new AutoFilter(0, 1, count(self::HEADERS) - 1, $total + 1));
        }
        $writer->addRow(new Row(array_map(fn ($h) => new StringCell($h, $bold), self::HEADERS)));

        $perStreet = [];  // "district|street" => [downloads, [user id => true]]
        $n = 0;
        foreach ($query->with('user')->lazy(1000) as $d) {
            $u = $d->user;
            $writer->addRow(new Row([
                new NumericCell(++$n, null),
                self::text($d->sent_at?->copy()->setTimezone($tz)->format('d.m.Y H:i:s')),
                self::text($u?->full_name),
                self::text($u?->phone),
                self::text($u?->username ? '@'.$u->username : null),
                self::text($d->user_id),
                self::text($d->district),
                self::text($d->street),
                self::text($d->file_name),
            ]));
            $key = $d->district."\0".$d->street;
            $perStreet[$key][0] = ($perStreet[$key][0] ?? 0) + 1;
            $perStreet[$key][1][$d->user_id] = true;
        }

        $summary = $writer->addNewSheetAndMakeItCurrent();
        $summary->setName('Ko‘chalar bo‘yicha');
        $summary->setColumnWidth(24, 1);
        $summary->setColumnWidth(34, 2);
        $summary->setColumnWidth(16, 3);
        $summary->setColumnWidth(18, 4);
        $writer->addRow(new Row(array_map(fn ($h) => new StringCell($h, $bold), ['Tuman', 'Ko‘cha', 'Yuklab olish', 'Foydalanuvchilar'])));
        uasort($perStreet, fn ($a, $b) => $b[0] <=> $a[0]);
        foreach ($perStreet as $key => [$count, $users]) {
            [$district, $street] = explode("\0", $key);
            $writer->addRow(new Row([
                self::text($district), self::text($street), new NumericCell($count, null), new NumericCell(count($users), null),
            ]));
        }

        $writer->close();

        return $n;
    }

    private static function text(mixed $value): StringCell
    {
        return new StringCell((string) ($value ?? ''), null);
    }
}
