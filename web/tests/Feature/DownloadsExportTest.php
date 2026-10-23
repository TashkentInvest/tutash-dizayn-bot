<?php

namespace Tests\Feature;

use App\Filament\Resources\Downloads\Pages\ListDownloads;
use App\Models\Download;
use App\Models\User;
use App\Services\DownloadsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/** Works on a COPY of the bot database with a few synthetic rows, never on the real one. */
class DownloadsExportTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/export-'.uniqid());
        File::makeDirectory($this->tmp, 0777, true);
        copy(base_path('../bot.db'), $this->tmp.'/bot.db');
        config(['database.connections.bot.database' => $this->tmp.'/bot.db']);
        DB::purge('bot');

        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        $bot = DB::connection('bot');
        $bot->table('downloads')->delete();
        $bot->table('users')->whereIn('user_id', [9001, 9002])->delete();
        $bot->table('users')->insert([
            ['user_id' => 9001, 'username' => 'ali', 'full_name' => '=HYPERLINK("http://evil.example","click")', 'phone' => '+998901112233', 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-01-01 00:00:00', 'start_count' => 1],
            ['user_id' => 9002, 'username' => null, 'full_name' => 'Vali Valiyev', 'phone' => '+998907778899', 'first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-01-01 00:00:00', 'start_count' => 1],
        ]);
        $bot->table('downloads')->insert([
            ['user_id' => 9001, 'district' => 'Uchtepa tumani', 'street' => 'Lutfiy', 'file_name' => 'a.pdf', 'sent_at' => '2026-01-01 20:00:00'],  // 01:00 next day in Tashkent
            ['user_id' => 9002, 'district' => 'Uchtepa tumani', 'street' => 'Lutfiy', 'file_name' => 'a.pdf', 'sent_at' => '2026-01-02 08:00:00'],
            ['user_id' => 9002, 'district' => 'Uchtepa tumani', 'street' => 'Zargarlik', 'file_name' => 'b.pdf', 'sent_at' => '2026-01-03 08:00:00'],
            ['user_id' => 9001, 'district' => 'Sergeli tumani', 'street' => 'Shokirariq', 'file_name' => 'c.pdf', 'sent_at' => '2026-01-04 08:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('bot');
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /** @return array<string, array<int, array<int, mixed>>> sheet name => rows */
    private function read(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $out[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $out;
    }

    public function test_export_has_the_right_columns_rows_and_local_time(): void
    {
        $path = $this->tmp.'/all.xlsx';
        $n = DownloadsExport::write(Download::query()->orderBy('sent_at'), $path);
        $sheets = $this->read($path);

        $this->assertSame(4, $n);
        $rows = $sheets['Yuklab olishlar'];
        $this->assertSame(DownloadsExport::HEADERS, $rows[0]);
        $this->assertCount(5, $rows);                                  // header + 4 downloads
        $this->assertSame('02.01.2026 01:00:00', $rows[1][1], 'UTC time shown as Tashkent time (+5)');
        $this->assertSame('Vali Valiyev', $rows[2][2]);
        $this->assertSame('+998907778899', $rows[2][3]);
        $this->assertSame('@ali', $rows[1][4]);
        $this->assertSame('9001', (string) $rows[1][5]);
        $this->assertSame(['Uchtepa tumani', 'Lutfiy', 'a.pdf'], [$rows[1][6], $rows[1][7], $rows[1][8]]);

        // second sheet: totals per street, biggest first, with distinct users
        $summary = $sheets['Ko‘chalar bo‘yicha'];
        $this->assertSame(['Tuman', 'Ko‘cha', 'Yuklab olish', 'Foydalanuvchilar'], $summary[0]);
        $this->assertSame(['Uchtepa tumani', 'Lutfiy', 2, 2], $summary[1]);
    }

    public function test_a_name_that_looks_like_a_formula_stays_text(): void
    {
        $path = $this->tmp.'/f.xlsx';
        DownloadsExport::write(Download::query()->orderBy('sent_at'), $path);

        $zip = new ZipArchive();
        $zip->open($path);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $strings = (string) $zip->getFromName('xl/sharedStrings.xml').$sheetXml;
        $zip->close();

        $this->assertStringNotContainsString('<f>', $sheetXml, 'no formula cell is written');
        $this->assertStringContainsString('HYPERLINK', $strings, 'the text itself is kept');
        $this->assertSame('=HYPERLINK("http://evil.example","click")', $this->read($path)['Yuklab olishlar'][1][2]);
    }

    public function test_the_button_exports_only_what_the_filters_show(): void
    {
        $page = Livewire::test(ListDownloads::class)
            ->set('tableFilters.district.values', ['Sergeli tumani'])
            ->callAction('export')
            ->assertFileDownloaded();

        $file = $this->tmp.'/button.xlsx';
        file_put_contents($file, base64_decode($page->effects['download']['content']));
        $rows = $this->read($file)['Yuklab olishlar'];

        $this->assertCount(2, $rows, 'header + the single Sergeli download');
        $this->assertSame('Shokirariq', $rows[1][7]);
        $this->assertStringStartsWith('yuklab-olishlar-', $page->effects['download']['name']);
        $this->assertStringEndsWith('.xlsx', $page->effects['download']['name']);
    }

    public function test_nothing_to_export_gives_a_message_not_an_empty_file(): void
    {
        Livewire::test(ListDownloads::class)
            ->set('tableFilters.district.values', ['No such district'])
            ->callAction('export')
            ->assertNoFileDownloaded()
            ->assertNotified();
    }

    public function test_only_admins_can_reach_the_export_page(): void
    {
        $this->actingAs(User::factory()->create());   // valid login but not in ADMIN_EMAILS
        $this->get('/admin/downloads')->assertForbidden();
    }
}
