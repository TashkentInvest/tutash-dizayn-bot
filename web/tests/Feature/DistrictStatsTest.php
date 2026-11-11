<?php

namespace Tests\Feature;

use App\Filament\NavGroups;
use App\Filament\Resources\BotUsers\BotUserResource;
use App\Filament\Resources\Downloads\DownloadResource;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Widgets\TopDistricts;
use App\Filament\Widgets\TopStreets;
use App\Models\Download;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** District download ranking on the dashboard and the citizens' menu group. Runs against a COPY of the bot database. */
class DistrictStatsTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = storage_path('framework/testing/stats-'.uniqid());
        File::makeDirectory($this->tmp, 0777, true);
        copy(base_path('../tests/fixtures/bot.sqlite'), $this->tmp.'/bot.db');
        config(['database.connections.bot.database' => $this->tmp.'/bot.db']);
        DB::purge('bot');
        DB::connection('bot')->table('downloads')->delete();

        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        $old = now()->utc()->subDays(40)->format('Y-m-d H:i:s');
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [
            // Mirobod: 3 downloads by 2 people on 2 streets, today
            [1, 'Миробод тумани', 'Истиқбол кўчаси', $now], [1, 'Миробод тумани', 'Истиқбол кўчаси', $now], [2, 'Миробод тумани', 'Локомотив кўчаси', $now],
            // Chilonzor: 4 downloads, all 40 days ago
            [3, 'Чилонзор тумани', 'Чўпонота кўчаси', $old], [3, 'Чилонзор тумани', 'Чўпонота кўчаси', $old], [4, 'Чилонзор тумани', 'Чўпонота кўчаси', $old], [5, 'Чилонзор тумани', 'Чўпонота кўчаси', $old],
            // design code: not a district
            [6, TopDistricts::DESIGN_CODE, 'Ўзбекча', $now], [7, TopDistricts::DESIGN_CODE, 'Русский', $now],
        ];
        foreach ($rows as [$uid, $district, $street, $at]) {
            DB::connection('bot')->table('users')->insertOrIgnore(['user_id' => 900000 + $uid, 'first_name' => 'T']);
            DB::connection('bot')->table('downloads')->insert(['user_id' => 900000 + $uid, 'district' => $district, 'street' => $street, 'file_name' => 'x.pdf', 'sent_at' => $at]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('bot');
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_districts_ranked_by_downloads_without_the_design_code(): void
    {
        $w = Livewire::test(TopDistricts::class);
        $records = $w->instance()->getTableRecords();

        $this->assertSame(['Чилонзор тумани', 'Миробод тумани'], $records->pluck('district')->all());
        $this->assertSame([4, 3], $records->pluck('total')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([3, 2], $records->pluck('people')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1, 2], $records->pluck('streets')->map(fn ($v) => (int) $v)->all());
        $w->assertSee('57% ulush')->assertSee('43% ulush')->assertDontSee(TopDistricts::DESIGN_CODE);
    }

    public function test_period_filter_keeps_only_recent_downloads(): void
    {
        $w = Livewire::test(TopDistricts::class)->filterTable('period', '7');
        $records = $w->instance()->getTableRecords();

        $this->assertSame(['Миробод тумани'], $records->pluck('district')->all());
        $w->assertSee('100% ulush');

        $streets = Livewire::test(TopStreets::class)->filterTable('period', 'today')->instance()->getTableRecords();
        // today: two Mirobod streets + the two design code languages; the 40-day-old Chilonzor street is gone
        $this->assertSame(4, $streets->count());
        $this->assertNotContains('Чўпонота кўчаси', $streets->pluck('street')->all());
    }

    public function test_citizen_sections_share_one_menu_group_and_dashboard_opens(): void
    {
        foreach ([BotUserResource::class, DownloadResource::class, FeedbackResource::class] as $r) {
            $this->assertSame(NavGroups::CITIZENS, $r::getNavigationGroup());
        }
        // widgets load lazily, so the page itself shows the menu; the widget is tested above
        $this->get('/admin')->assertOk()->assertSee(NavGroups::CITIZENS)->assertSee('Aholi (bot foydalanuvchilari)');
    }
}
