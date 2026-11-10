<?php

namespace App\Services;

use App\Filament\Widgets\TopDistricts;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The most-downloaded files as an ordered report (.xlsx):
 *   1 "Tumanlar"        - all 12 districts ranked by downloads, share, people, streets; total row
 *   2 "Ko‘chalar"       - district by district (in rank order), its streets ranked, with a subtotal per district
 *   3 "Fayllar"         - every file ranked by downloads
 *   4 "Boshqa fayllar"  - downloads that are not a district street (the design code PDFs)
 * Texts are STRING cells: a street or file name starting with "=" must never become a formula.
 */
class DownloadsReport
{
    public static function write(string $path, ?CarbonInterface $since, string $periodLabel): void
    {
        $db = DB::connection('bot');
        $base = fn () => $db->table('downloads')
            ->when($since, fn ($q) => $q->where('sent_at', '>=', $since->copy()->utc()->format('Y-m-d H:i:s')));
        $districtNames = $db->table('districts')->orderBy('sort_order')->pluck('name')->all();
        $isDistrict = fn (string $d) => $d !== TopDistricts::DESIGN_CODE && in_array($d, $districtNames, true);

        $byDistrict = $base()->selectRaw('district, COUNT(*) AS total, COUNT(DISTINCT user_id) AS people, COUNT(DISTINCT street) AS streets')
            ->groupBy('district')->get()->keyBy('district');
        $byStreet = $base()->selectRaw('district, street, COUNT(*) AS total, COUNT(DISTINCT user_id) AS people')
            ->groupBy('district', 'street')->get();
        $byFile = $base()->selectRaw('district, street, file_name, COUNT(*) AS total, COUNT(DISTINCT user_id) AS people')
            ->groupBy('district', 'street', 'file_name')->get();

        // every district, also those nobody downloaded from yet; ranked by downloads, ties in the usual district order
        $districts = collect($districtNames)->map(fn ($name, $i) => (object) [
            'name' => $name, 'order' => $i,
            'total' => (int) ($byDistrict[$name]->total ?? 0),
            'people' => (int) ($byDistrict[$name]->people ?? 0),
            'streets' => (int) ($byDistrict[$name]->streets ?? 0),
        ])->sort(fn ($a, $b) => [$b->total, $a->order] <=> [$a->total, $b->order])->values();
        $districtTotal = $districts->sum('total');
        $peopleAll = (int) $base()->whereIn('district', $districtNames)->distinct()->count('user_id');

        $w = new Writer();
        $w->openToFile($path);
        $head = (new Style())->setFontBold()->setBackgroundColor('E8EEF7');
        $bold = (new Style())->setFontBold();
        $group = (new Style())->setFontBold()->setBackgroundColor('F3F4F6');
        $pct = (new Style())->setFormat('0.0%');
        $pctBold = (new Style())->setFontBold()->setFormat('0.0%');
        $title = (new Style())->setFontBold()->setFontSize(13);
        $muted = (new Style())->setFontColor(Color::rgb(107, 114, 128));
        $meta = fn (string $t) => [
            new Row([new StringCell($t, $title)]),
            new Row([new StringCell('Davr: '.$periodLabel.' · Tuzilgan: '.now(config('app.display_timezone'))->format('d.m.Y H:i'), $muted)]),
            new Row([]),
        ];

        // 1 - districts
        $s = $w->getCurrentSheet();
        $s->setName('Tumanlar');
        self::widths($s, [6, 30, 16, 12, 14, 18]);
        $s->setSheetView((new SheetView())->setFreezeRow(5));
        $w->addRows($meta('Tumanlar reytingi: konsepsiya fayllarini yuklab olish'));
        $w->addRow(self::row(['№', 'Tuman', 'Yuklab olish', 'Ulush', 'Fuqarolar', 'Ko‘chalar (yuklangan)'], $head));
        foreach ($districts as $i => $d) {
            $w->addRow(new Row([
                new NumericCell($i + 1, null), self::t($d->name), new NumericCell($d->total, null),
                new NumericCell($districtTotal ? $d->total / $districtTotal : 0, $pct), new NumericCell($d->people, null), new NumericCell($d->streets, null),
            ]));
        }
        $w->addRow(new Row([
            new StringCell('', $bold), new StringCell('Jami', $bold), new NumericCell($districtTotal, $bold),
            new NumericCell($districtTotal ? 1 : 0, $pctBold), new NumericCell($peopleAll, $bold), new NumericCell($districts->sum('streets'), $bold),
        ]));

        // 2 - streets, district by district
        $s = $w->addNewSheetAndMakeItCurrent();
        $s->setName('Ko‘chalar');
        self::widths($s, [6, 28, 40, 16, 14, 18]);
        $s->setSheetView((new SheetView())->setFreezeRow(5));
        $w->addRows($meta('Ko‘chalar reytingi: tumanlar kesimida'));
        $w->addRow(self::row(['№', 'Tuman', 'Ko‘cha', 'Yuklab olish', 'Fuqarolar', 'Tumandagi ulush'], $head));
        $streets = $byStreet->groupBy('district');
        foreach ($districts as $i => $d) {
            $w->addRow(new Row([
                new StringCell(($i + 1).'.', $group), new StringCell($d->name, $group), new StringCell('Tuman jami', $group),
                new NumericCell($d->total, $group), new NumericCell($d->people, $group), new StringCell('', $group),
            ]));
            $rows = ($streets[$d->name] ?? collect())->sortByDesc('total')->values();
            if ($rows->isEmpty()) {
                $w->addRow(new Row([new StringCell('', null), new StringCell('', null), new StringCell('Hali yuklab olinmagan', $muted)]));
            }
            foreach ($rows as $j => $r) {
                $w->addRow(new Row([
                    new NumericCell($j + 1, null), self::t($d->name), self::t($r->street), new NumericCell((int) $r->total, null),
                    new NumericCell((int) $r->people, null), new NumericCell($d->total ? $r->total / $d->total : 0, $pct),
                ]));
            }
            $w->addRow(new Row([]));
        }

        // 3 - files
        $s = $w->addNewSheetAndMakeItCurrent();
        $s->setName('Fayllar');
        self::widths($s, [6, 28, 36, 60, 16, 14]);
        $s->setSheetView((new SheetView())->setFreezeRow(5));
        $w->addRows($meta('Eng ko‘p yuklab olingan fayllar'));
        $w->addRow(self::row(['№', 'Tuman', 'Ko‘cha', 'Fayl', 'Yuklab olish', 'Fuqarolar'], $head));
        foreach ($byFile->filter(fn ($r) => $isDistrict($r->district))->sortByDesc('total')->values() as $i => $r) {
            $w->addRow(new Row([
                new NumericCell($i + 1, null), self::t($r->district), self::t($r->street), self::t($r->file_name),
                new NumericCell((int) $r->total, null), new NumericCell((int) $r->people, null),
            ]));
        }

        // 4 - everything that is not a district street: the design code (by language) and anything else
        $s = $w->addNewSheetAndMakeItCurrent();
        $s->setName('Boshqa fayllar');
        self::widths($s, [6, 24, 24, 60, 16, 14]);
        $w->addRows($meta('Boshqa fayllar (dizayn-kod va boshqalar)'));
        $w->addRow(self::row(['№', 'Bo‘lim', 'Turi / til', 'Fayl', 'Yuklab olish', 'Fuqarolar'], $head));
        $other = $byFile->reject(fn ($r) => $isDistrict($r->district))->sortByDesc('total')->values();
        foreach ($other as $i => $r) {
            $w->addRow(new Row([
                new NumericCell($i + 1, null), self::t($r->district), self::t($r->street), self::t($r->file_name),
                new NumericCell((int) $r->total, null), new NumericCell((int) $r->people, null),
            ]));
        }
        $w->addRow(new Row([
            new StringCell('', $bold), new StringCell('Jami', $bold), new StringCell('', $bold), new StringCell('', $bold),
            new NumericCell($other->sum('total'), $bold), new StringCell('', $bold),
        ]));

        $w->close();
    }

    public const PERIODS = ['all' => 'Butun davr', 'today' => 'Bugun', '7' => 'Oxirgi 7 kun', '30' => 'Oxirgi 30 kun'];

    /** The Excel file for a period key of PERIODS, as a download response (the temp file is removed after sending). */
    public static function download(?string $period): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $today = now(config('app.display_timezone'))->startOfDay();
        $since = match ($period) {
            'today' => $today, '7' => $today->copy()->subDays(6), '30' => $today->copy()->subDays(29), default => null,
        };
        $tmp = tempnam(sys_get_temp_dir(), 'rep');
        $path = $tmp.'.xlsx';
        rename($tmp, $path);
        self::write($path, $since, self::PERIODS[$period ?? 'all'] ?? self::PERIODS['all']);

        return response()->download($path, 'yuklab-olishlar-reyting-'.now(config('app.display_timezone'))->format('Ymd-Hi').'.xlsx')
            ->deleteFileAfterSend();
    }

    /** "Excel hisobot" button with a period choice, for the downloads page and the dashboard ranking. */
    public static function action(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('rankingReport')
            ->label('Excel: tuman va ko‘chalar reytingi')->icon('heroicon-o-document-chart-bar')->color('success')
            ->modalHeading('Eng ko‘p yuklab olingan fayllar — Excel hisobot')
            ->modalDescription('12 tuman reytingi, har bir tuman ichida ko‘chalar, fayllar va boshqa fayllar (dizayn-kod) — alohida varaqlarda.')
            ->modalSubmitActionLabel('Yuklab olish')
            ->schema([\Filament\Forms\Components\Select::make('period')->label('Davr')->options(self::PERIODS)->default('all')->required()->native(false)])
            ->action(fn (array $data) => self::download($data['period'] ?? 'all'));
    }

    /** @param list<string> $labels */
    private static function row(array $labels, Style $style): Row
    {
        return new Row(array_map(fn ($h) => new StringCell($h, $style), $labels));
    }

    private static function t(mixed $v): Cell
    {
        return new StringCell((string) ($v ?? ''), null);
    }

    /** @param list<int> $widths */
    private static function widths($sheet, array $widths): void
    {
        foreach ($widths as $i => $wd) {
            $sheet->setColumnWidth($wd, $i + 1);
        }
    }
}
