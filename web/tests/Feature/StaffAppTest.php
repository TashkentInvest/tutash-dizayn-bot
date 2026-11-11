<?php

namespace Tests\Feature;

use App\Filament\Pages\DesignCodeSvod;
use App\Filament\Resources\DesignObjects\Pages\ListDesignObjects;
use App\Filament\Resources\DesignObjects\Pages\ViewDesignObject;
use App\Filament\Resources\Inspections\Pages\ListInspections;
use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Http\Middleware\TelegramWebApp;
use App\Models\DesignObject;
use App\Models\Inspection;
use App\Models\Staff;
use App\Models\User;
use App\Services\DesignCodeSvod as Svod;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * District staff: Mini App (initData auth, the evidence wizard) and the dashboard (moderation, svod).
 * Runs against a COPY of the bot database and temporary folders.
 */
class StaffAppTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:TEST-token';

    private string $tmp;

    private int $district;

    private int $otherDistrict;

    private int $street;

    private Staff $staff;

    private DesignObject $object;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/staff-'.uniqid());
        File::makeDirectory($this->tmp.'/data', 0777, true);
        File::makeDirectory($this->tmp.'/uploads', 0777, true);
        copy(base_path('../tests/fixtures/bot.sqlite'), $this->tmp.'/bot.db');
        config([
            'database.connections.bot.database' => $this->tmp.'/bot.db',
            'filesystems.disks.bot_data.root' => $this->tmp.'/data',
            'bot.uploads_path' => $this->tmp.'/uploads',
            'telegram.bot_token' => self::TOKEN,
        ]);
        DB::purge('bot');
        $db = DB::connection('bot');
        foreach (['inspections', 'objects', 'staff', 'outbox'] as $t) {
            $db->table($t)->delete();
        }
        $this->district = (int) $db->table('districts')->orderBy('id')->value('id');
        $this->otherDistrict = (int) $db->table('districts')->where('id', '!=', $this->district)->orderBy('id')->value('id');
        $this->street = (int) $db->table('streets')->where('district_id', $this->district)->orderBy('id')->value('id');
        // the official list: 3 businesses on this street, none elsewhere in the district
        $db->table('streets')->update(['planned_objects' => 0]);
        $db->table('streets')->where('id', $this->street)->update(['planned_objects' => 3]);
        $db->table('street_files')->where('street_id', $this->street)->delete();
        file_put_contents($this->tmp.'/data/concept.pdf', "%PDF-1.4\n%test\n");
        $db->table('street_files')->insert(['street_id' => $this->street, 'file_name' => 'Konsepsiya.pdf', 'path' => 'concept.pdf', 'size' => 15]);

        $db->table('users')->insertOrIgnore(['user_id' => 555001, 'first_name' => 'S', 'lang' => 'uz']);
        $this->staff = Staff::create(['full_name' => 'Test Xodim', 'phone' => '+998901110001', 'district_id' => $this->district, 'is_active' => true]);
        $db->table('staff')->where('id', $this->staff->id)->update(['user_id' => 555001]);
        $this->staff->refresh();
        $oid = $db->table('objects')->insertGetId(['street_id' => $this->street, 'shop_name' => 'Market 24']);
        $this->object = DesignObject::findOrFail($oid);
    }

    protected function tearDown(): void
    {
        DB::purge('bot');
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function jpeg(int $gray): string
    {
        $im = imagecreatetruecolor(64, 48);
        imagefill($im, 0, 0, imagecolorallocate($im, $gray, $gray, (int) ($gray / 2)));
        // every picture different (duplicate check), without changing its brightness
        imagesetpixel($im, random_int(0, 63), random_int(0, 47), imagecolorallocate($im, random_int(0, 255), 0, 0));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    protected function headers(int $uid = 555001, ?int $authDate = null): array
    {
        return ['X-Telegram-Init-Data' => TelegramWebApp::sign(['id' => $uid, 'first_name' => 'S'], self::TOKEN, $authDate), 'Accept' => 'application/json'];
    }

    private function photo(int $gray = 200): UploadedFile
    {
        $p = $this->tmp.'/p'.uniqid().'.jpg';
        file_put_contents($p, $this->jpeg($gray));

        return new UploadedFile($p, 'photo.jpg', 'image/jpeg', null, true);
    }

    private function video(int $kb = 60): UploadedFile
    {
        $p = $this->tmp.'/v'.uniqid().'.webm';
        file_put_contents($p, "\x1A\x45\xDF\xA3".random_bytes($kb * 1024));

        return new UploadedFile($p, 'video.webm', 'video/webm', null, true);
    }

    /** Runs the whole wizard for the test object (photo, staff location, object point, video, comment); returns the id. */
    private function sendEvidence(string $kind, array $point = [41.3111, 69.2797], ?array $objectPoint = null): int
    {
        $objectPoint ??= [$point[0] + 0.0002, $point[1]]; // ~22 m from where the staff member stands
        $id = $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => $kind], $this->headers())->assertOk()->json('draft.id');
        $this->post("/tg/api/inspections/$id/photo", ['photo' => $this->photo()], $this->headers())->assertOk();
        $this->postJson("/tg/api/inspections/$id/location", ['latitude' => $point[0], 'longitude' => $point[1], 'accuracy' => 20], $this->headers())->assertOk();
        $this->postJson("/tg/api/inspections/$id/point", ['latitude' => $objectPoint[0], 'longitude' => $objectPoint[1]], $this->headers())->assertOk();
        $this->post("/tg/api/inspections/$id/video", ['video' => $this->video()], $this->headers())->assertOk();
        $this->postJson("/tg/api/inspections/$id/submit", ['comment' => 'Eski peshtaxta olib tashlandi'], $this->headers())->assertOk();

        return $id;
    }

    // ------------------------------------------------------------------ authentication

    public function test_init_data_signature_and_age_are_checked(): void
    {
        $this->getJson('/tg/api/me', $this->headers())->assertOk()->assertJsonPath('staff.name', 'Test Xodim');
        $this->getJson('/tg/api/me')->assertStatus(401)->assertJsonPath('error', TelegramWebApp::EXPIRED);

        $forged = str_replace('first_name', 'first_nam3', $this->headers()['X-Telegram-Init-Data']);
        $this->getJson('/tg/api/me', ['X-Telegram-Init-Data' => $forged])->assertStatus(401);

        // a Mini App left open for hours still works, one older than a day does not
        $this->getJson('/tg/api/me', $this->headers(555001, time() - 20 * 3600))->assertOk();
        $this->getJson('/tg/api/me', $this->headers(555001, time() - 25 * 3600))->assertStatus(401);

        // a genuine Telegram user who is not staff
        $this->getJson('/tg/api/me', $this->headers(999999))->assertStatus(403)->assertJsonPath('error', TelegramWebApp::NOT_STAFF);
        $this->get('/tg/app')->assertOk()->assertSee('telegram-web-app.js', false);
    }

    public function test_mini_app_api_needs_no_csrf_token_but_the_admin_does(): void
    {
        // tests skip CSRF checks, so run the real middleware the way production does
        $mw = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $pass = fn () => response('ok');
        $tg = Request::create('/tg/api/inspections/1/submit', 'POST');
        $tg->setLaravelSession(app('session.store'));
        $this->assertSame('ok', $mw->handle($tg, $pass)->getContent());

        $admin = Request::create('/admin/xodimlar', 'POST');
        $admin->setLaravelSession(app('session.store'));
        $this->expectException(TokenMismatchException::class);
        $mw->handle($admin, $pass);
    }

    // ------------------------------------------------------------------ the wizard

    public function test_full_wizard_creates_one_pending_inspection_and_leaves_the_object_unchanged(): void
    {
        $me = $this->getJson('/tg/api/me', $this->headers())->assertOk();
        $this->assertSame(3, $me->json('totals.total'), 'the total comes from the official list');
        $list = $this->getJson("/tg/api/streets/{$this->street}/objects", $this->headers())->assertOk();
        $this->assertSame('Market 24', $list->json('objects.0.shop_name'));
        // the street's whole PDF opens through a signed link (the phone's browser sends no initData)
        $this->get($list->json('files.0.url'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/tg/file/'.DB::connection('bot')->table('street_files')->where('street_id', $this->street)->value('id'))->assertForbidden();

        // staff add a business; sending it again (double tap) does not add it twice
        $h = $this->headers();
        $this->postJson("/tg/api/streets/{$this->street}/objects", ['shop_name' => ''], $h)->assertStatus(422);
        $new = $this->postJson("/tg/api/streets/{$this->street}/objects", ['shop_name' => 'Gul do‘koni', 'stir' => '123456789', 'owner_phone' => '90 123 45 67', 'latitude' => 41.3111, 'longitude' => 69.2797], $h)->assertOk();
        $this->postJson("/tg/api/streets/{$this->street}/objects", ['shop_name' => ' gul DO‘KONI ', 'latitude' => 41.3111, 'longitude' => 69.2797], $h)->assertOk()->assertJsonPath('already', true);
        $this->assertSame(2, DesignObject::query()->where('street_id', $this->street)->count());
        $this->assertSame('+998901234567', DesignObject::find($new->json('object.id'))->owner_phone);
        $this->assertSame($this->staff->id, DesignObject::find($new->json('object.id'))->created_by);
        $other = (int) DB::connection('bot')->table('streets')->where('district_id', $this->otherDistrict)->value('id');
        $this->postJson("/tg/api/streets/$other/objects", ['shop_name' => 'Boshqa'], $h)->assertStatus(403);

        $id = $this->sendEvidence('removed');
        $i = Inspection::findOrFail($id);
        $this->assertSame('submitted', $i->step);
        $this->assertSame(Inspection::PENDING, $i->review);
        $this->assertFileExists($this->tmp.'/uploads/'.$i->photo);
        $this->assertFileExists($this->tmp.'/uploads/'.$i->video);
        $this->assertSame(DesignObject::PENDING, $this->object->fresh()->status, 'only an approval changes the object');

        // while it waits for review, no new evidence for the same object
        $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => 'installed'], $this->headers())->assertStatus(409);
    }

    public function test_repeated_steps_are_idempotent_and_steps_cannot_be_skipped(): void
    {
        $h = $this->headers();
        $id = $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => 'removed'], $h)->json('draft.id');
        // pressed many times: still one draft
        $this->assertSame($id, $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => 'removed'], $h)->json('draft.id'));
        $this->assertSame(1, Inspection::count());

        $loc = ['latitude' => 41.3, 'longitude' => 69.2, 'accuracy' => 20];
        $this->postJson("/tg/api/inspections/$id/location", $loc, $h)->assertStatus(409); // the photo comes first
        $this->post("/tg/api/inspections/$id/photo", ['photo' => $this->photo()], $h)->assertOk();
        $this->postJson("/tg/api/inspections/$id/location", $loc, $h)->assertOk();
        // the answer got lost and the page sends it again: "already saved", no error
        $this->postJson("/tg/api/inspections/$id/location", $loc, $h)->assertOk()->assertJsonPath('already', true);
        $this->post("/tg/api/inspections/$id/video", ['video' => $this->video()], $h)->assertStatus(409); // the map point first
        $this->postJson("/tg/api/inspections/$id/point", ['latitude' => 41.3001, 'longitude' => 69.2001], $h)->assertOk();
        $this->assertSame('video', Inspection::find($id)->step);
        $this->post("/tg/api/inspections/$id/video", ['video' => $this->video()], $h)->assertOk();

        // a short comment is refused, but the photo and video stay: only the comment is asked again
        $this->postJson("/tg/api/inspections/$id/submit", ['comment' => 'ok'], $h)->assertStatus(422);
        $this->assertSame('comment', Inspection::find($id)->step);
        $this->postJson("/tg/api/inspections/$id/submit", ['comment' => 'Yangi peshtaxta qo‘yildi'], $h)->assertOk();
        $this->postJson("/tg/api/inspections/$id/submit", ['comment' => 'Yangi peshtaxta qo‘yildi'], $h)->assertOk()->assertJsonPath('already', true);
    }

    public function test_bad_evidence_is_refused(): void
    {
        $h = $this->headers();
        $id = $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => 'removed'], $h)->json('draft.id');
        $this->post("/tg/api/inspections/$id/photo", ['photo' => $this->photo(3)], $h)->assertStatus(422); // black frame
        $this->post("/tg/api/inspections/$id/photo", ['photo' => $this->photo()], $h)->assertOk();
        $this->postJson("/tg/api/inspections/$id/location", ['latitude' => 41.3, 'longitude' => 69.2, 'accuracy' => 400], $h)->assertStatus(422); // GPS too rough
        $this->postJson("/tg/api/inspections/$id/location", ['latitude' => 41.3, 'longitude' => 69.2, 'accuracy' => 20], $h)->assertOk();
        // the object marked on the map 1.1 km away from where the staff member stands
        $this->postJson("/tg/api/inspections/$id/point", ['latitude' => 41.31, 'longitude' => 69.2], $h)
            ->assertStatus(422)->assertJsonFragment(['error' => 'Tanlangan nuqta siz turgan joydan 1112 m uzoqda. Obyektni to‘g‘ri belgilang.']);
        $this->postJson("/tg/api/inspections/$id/point", ['latitude' => 41.3005, 'longitude' => 69.2], $h)->assertOk();
        $this->assertEqualsWithDelta(55.6, Inspection::find($id)->distance_m, 0.5);
        $this->post("/tg/api/inspections/$id/video", ['video' => $this->video(5)], $h)->assertStatus(422); // too short
    }

    public function test_far_from_the_known_place_is_refused_and_other_districts_are_closed(): void
    {
        $this->object->forceFill(['latitude' => 41.3111, 'longitude' => 69.2797])->save();
        $h = $this->headers();
        $id = $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => 'removed'], $h)->json('draft.id');
        $this->post("/tg/api/inspections/$id/photo", ['photo' => $this->photo()], $h)->assertOk();
        $this->postJson("/tg/api/inspections/$id/location", ['latitude' => 41.3300, 'longitude' => 69.2797, 'accuracy' => 10], $h)
            ->assertStatus(422)->assertJsonFragment(['error' => 'Siz obyektdan 2102 m uzoqdasiz. Obyekt yoniga boring.']);
        $this->postJson("/tg/api/inspections/$id/location", ['latitude' => 41.3112, 'longitude' => 69.2798, 'accuracy' => 10], $h)->assertOk();

        $other = (int) DB::connection('bot')->table('streets')->where('district_id', $this->otherDistrict)->value('id');
        $this->getJson("/tg/api/streets/$other/objects", $h)->assertStatus(403);
    }

    public function test_a_started_inspection_with_media_is_not_dropped_silently(): void
    {
        $h = $this->headers();
        $id = $this->postJson("/tg/api/objects/{$this->object->id}/inspections", ['kind' => 'removed'], $h)->json('draft.id');
        $this->post("/tg/api/inspections/$id/photo", ['photo' => $this->photo()], $h)->assertOk();

        $second = DesignObject::findOrFail(DB::connection('bot')->table('objects')->insertGetId(
            ['street_id' => $this->street, 'shop_name' => 'Ikkinchi do‘kon']));
        $this->postJson("/tg/api/objects/{$second->id}/inspections", ['kind' => 'removed'], $h)
            ->assertStatus(409)->assertJsonPath('other.object_id', $this->object->id);
        $this->assertNotNull(Inspection::find($id), 'kept until the user says so');
        $this->postJson("/tg/api/objects/{$second->id}/inspections", ['kind' => 'removed', 'discard_other' => true], $h)->assertOk();
        $this->assertNull(Inspection::find($id));
        $this->assertDirectoryDoesNotExist($this->tmp."/uploads/inspections/$id");
    }

    // ------------------------------------------------------------------ dashboard

    public function test_approval_changes_the_object_and_tells_the_staff_member(): void
    {
        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        $id = $this->sendEvidence('removed');
        Livewire::test(ListInspections::class)->assertCanSeeTableRecords([Inspection::find($id)])
            ->callAction(TestAction::make('approve')->table(Inspection::find($id)), ['note' => 'Yaxshi'])->assertHasNoActionErrors();
        $o = $this->object->fresh();
        $this->assertSame(DesignObject::REMOVED, $o->status);
        $this->assertEqualsWithDelta(41.3113, $o->latitude, 0.00001, 'the point picked on the map becomes the object place');
        $msg = DB::connection('bot')->table('outbox')->where('user_id', 555001)->value('text');
        $this->assertStringContainsString('tasdiqlandi', $msg);

        // the next evidence: installed, rejected with a reason -> status stays, staff told why
        $id2 = $this->sendEvidence('installed', [41.3112, 69.2798]);
        Livewire::test(ListInspections::class)
            ->callAction(TestAction::make('reject')->table(Inspection::find($id2)), ['reason' => ''])->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame(Inspection::PENDING, Inspection::find($id2)->review, 'no reason, no rejection');
        Livewire::test(ListInspections::class)
            ->callAction(TestAction::make('reject')->table(Inspection::find($id2)), ['reason' => 'Rasmda peshtaxta ko‘rinmayapti'])->assertHasNoActionErrors();
        $this->assertSame(DesignObject::REMOVED, $this->object->fresh()->status);
        $this->assertStringContainsString('Rasmda peshtaxta ko‘rinmayapti', DB::connection('bot')->table('outbox')->orderByDesc('id')->value('text'));

        // admin pages open: svod, objects list and the object page (street PDF + evidence), staff list, media
        Livewire::test(DesignCodeSvod::class)->assertSee('Toshkent shahar jami')->call('pick', $this->district)->assertSee('ko‘chalar kesimida');
        Livewire::test(ListDesignObjects::class)->assertCanSeeTableRecords([$o]);
        Livewire::test(ViewDesignObject::class, ['record' => $o->id])->assertSee('Konsepsiya.pdf')->assertSee('Eski peshtaxta olib tashlandi');
        Livewire::test(ManageStaff::class)->assertCanSeeTableRecords([$this->staff]);
        $this->get(route('bot-inspection-media', [$id, 'video']))->assertOk();
    }

    public function test_svod_counts_objects_once_per_status_and_the_days_work(): void
    {
        $id = $this->sendEvidence('installed');
        $day = Svod::reportDay();  // after 18:00 the work counts for the next day
        $row = collect(Svod::byDistrict($day)['rows'])->firstWhere('id', $this->district);
        $this->assertSame(['total' => 3, 'pending' => 3, 'installed' => 0, 'review' => 1, 'day_installed' => 0],
            array_intersect_key($row, array_flip(['total', 'pending', 'installed', 'review', 'day_installed'])));

        \App\Services\InspectionReview::approve(Inspection::find($id), null, 'test');
        $svod = Svod::byDistrict($day);
        $row = collect($svod['rows'])->firstWhere('id', $this->district);
        // total from the official list (3); one approved "installed" -> 2 still waiting, 33.3 %
        $this->assertSame([3, 2, 1, 0, 1, 33.3], [$row['total'], $row['pending'], $row['installed'], $row['review'], $row['day_installed'], $row['pct']]);
        $this->assertSame($svod['total']['installed'], array_sum(array_column($svod['rows'], 'installed')), 'the total row is the sum of the rows');
        $street = collect(Svod::byStreet($this->district, $day)['rows'])->firstWhere('id', $this->street);
        $this->assertSame(1, $street['installed']);
        $this->assertSame(0, collect(Svod::byDistrict($day->copy()->subDay())['rows'])->firstWhere('id', $this->district)['day_installed']);
    }

    public function test_dashboard_widgets_render_with_todays_work(): void
    {
        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email], 'cache.default' => 'array']);
        $this->actingAs($admin);
        $this->sendEvidence('removed');
        DB::connection('bot')->table('feedback')->insert(['user_id' => 555001, 'level' => 'high', 'text' => 'Ko‘chada yoritish yo‘q']);

        $w = \App\Services\BotStats::work();
        $this->assertSame([1, 1, 1, 3], [$w['staff_linked'], $w['review'], $w['evidence_today'], $w['planned']]);
        $this->assertGreaterThanOrEqual(1, $w['feedback_open']);

        Livewire::test(\App\Filament\Widgets\StatsOverview::class)->assertSee('Dalillar tasdiq kutmoqda')->assertSee('1 / 1');
        Livewire::test(\App\Filament\Widgets\ActivityChart::class)->assertOk();
        Livewire::test(\App\Filament\Widgets\DesignCodeProgressChart::class)->assertOk();
        Livewire::test(\App\Filament\Widgets\StaffActivity::class)->assertCanSeeTableRecords([$this->staff])->assertSee('Test Xodim');
        Livewire::test(\App\Filament\Widgets\PendingWork::class)->assertSee('Ko‘chada yoritish yo‘q')->assertSee('Market 24');
        Livewire::test(\App\Filament\Widgets\TopDistricts::class)->assertOk();
        Livewire::test(\App\Filament\Widgets\TopStreets::class)->assertOk();
        $this->get('/admin')->assertOk()->assertSee('Bosh sahifa');
        $this->get(\App\Filament\Resources\Streets\StreetResource::getUrl('index'))->assertOk()->assertSee('Barcha fayllar / dublikatlar');
    }

    public function test_downloads_ranking_report_is_ordered_by_district_and_street(): void
    {
        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
        $db = DB::connection('bot');
        $db->table('downloads')->delete();
        $names = $db->table('districts')->orderBy('sort_order')->pluck('name')->all();
        $last = end($names);  // the last district in the list gets the most downloads: it must come first
        $now = now()->utc()->format('Y-m-d H:i:s');
        $add = fn ($d, $s, $f, $n, $u = 600000) => collect(range(1, $n))->each(fn ($i) => $db->table('downloads')
            ->insert(['user_id' => $u + $i, 'district' => $d, 'street' => $s, 'file_name' => $f, 'sent_at' => $now]));
        $add($last, 'B ko‘cha', 'b.pdf', 5);
        $add($last, 'A ko‘cha', 'a.pdf', 2);
        $add($names[0], '=HYPERLINK("x")', 'c.pdf', 1);
        $add(\App\Filament\Widgets\TopDistricts::DESIGN_CODE, 'Ўзбекча', 'dk.pdf', 3);

        $path = $this->tmp.'/rep.xlsx';
        \App\Services\DownloadsReport::write($path, null, 'Butun davr');
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['Tumanlar', 'Ko‘chalar', 'Fayllar', 'Boshqa fayllar'], array_keys($sheets));
        $body = fn ($rows) => array_slice($rows, array_search('№', array_column($rows, 0), true) + 1);  // rows under the header
        $districtRows = array_slice($body($sheets['Tumanlar']), 0, count($names));
        $this->assertCount(count($names), $districtRows);  // every district, also those with no downloads
        $this->assertSame([1, $last, 7], [$districtRows[0][0], $districtRows[0][1], $districtRows[0][2]]);
        $this->assertSame(['', 'Jami', 8], array_slice(end($sheets['Tumanlar']), 0, 3));
        // streets: the leading district first, its streets by downloads
        $streetRows = array_values(array_filter($sheets['Ko‘chalar'], fn ($r) => ($r[1] ?? null) === $last && ($r[2] ?? null) !== 'Tuman jami'));
        $this->assertSame(['B ko‘cha', 'A ko‘cha'], array_column($streetRows, 2));
        $this->assertContains('=HYPERLINK("x")', array_merge(...$sheets['Ko‘chalar']));  // stays text
        $this->assertSame('dk.pdf', $body($sheets['Boshqa fayllar'])[0][3]);
        $this->assertNotContains('dk.pdf', array_column($sheets['Fayllar'], 3));

        Livewire::test(\App\Filament\Resources\Downloads\Pages\ListDownloads::class)
            ->callAction('rankingReport', ['period' => '7'])->assertFileDownloaded();
        Livewire::test(\App\Filament\Widgets\TopDistricts::class)
            ->callAction(TestAction::make('rankingReport')->table(), ['period' => 'all'])->assertFileDownloaded();
    }

    public function test_svod_report_day_ends_at_18_and_report_pictures_are_queued_for_the_bot(): void
    {
        $tz = config('app.display_timezone');
        $this->assertSame('2026-11-10', Svod::reportDay(\Carbon\Carbon::parse('2026-11-10 17:59', $tz))->toDateString());
        $this->assertSame('2026-11-11', Svod::reportDay(\Carbon\Carbon::parse('2026-11-10 18:00', $tz))->toDateString());
        [$from, $to] = Svod::window(\Carbon\Carbon::parse('2026-11-11', $tz));
        $this->assertSame(['2026-11-10 13:00:00', '2026-11-11 13:00:00'], [$from, $to]);  // 18:00 Tashkent = 13:00 UTC

        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
        $db = DB::connection('bot');
        $db->table('users')->insert(['user_id' => 777001, 'first_name' => 'Rahbar', 'full_name' => 'Rahbar Test']);
        \App\Models\ReportRecipient::create(['user_id' => 777001, 'label' => 'rahbar']);
        $db->table('outbox')->delete();

        Livewire::test(DesignCodeSvod::class)
            ->assertSee('Jarayonda')->assertSee('Bugun yechildi')->assertDontSee('Tekshiruvda')
            ->callAction('send', ['kinds' => ['svod', 'progress'], 'to' => [777001]])
            ->assertHasNoActionErrors();

        $rows = $db->table('outbox')->where('user_id', 777001)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $file = $this->tmp.'/uploads/'.$r->photo;
            $this->assertFileExists($file);
            $this->assertSame("\x89PNG", substr(file_get_contents($file), 0, 4));
        }
        $this->assertStringContainsString('Tumanlar kesimida svod', $rows[0]->text);

        Livewire::test(DesignCodeSvod::class)->callAction('send', ['kinds' => ['svod'], 'to' => []])->assertHasActionErrors(['to']);
        // linked staff are offered too, without being added as recipients
        $this->staff->forceFill(['user_id' => 555001])->save();
        $db->table('outbox')->delete();
        Livewire::test(DesignCodeSvod::class)->callAction('send', ['kinds' => ['progress'], 'to' => [555001]])->assertHasNoActionErrors();
        $this->assertSame(1, $db->table('outbox')->where('user_id', 555001)->count());
        Livewire::test(\App\Filament\Resources\ReportRecipients\Pages\ManageReportRecipients::class)->assertSee('Rahbar Test')->assertSee('rahbar');
    }

    public function test_admin_links_a_staff_member_to_a_bot_account_by_hand(): void
    {
        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
        $db = DB::connection('bot');
        $db->table('users')->insert(['user_id' => 888001, 'first_name' => 'Q', 'full_name' => 'Qo‘lda Bog‘lanadi', 'phone' => '+998901110022']);
        $s = Staff::create(['full_name' => 'Yangi Xodim', 'phone' => '+998901110022', 'district_id' => $this->district, 'is_active' => true]);
        $db->table('outbox')->delete();

        Livewire::test(ManageStaff::class)
            ->assertTableActionVisible('link', $s)
            ->assertTableActionHidden('link', $this->staff)  // already linked
            ->callTableAction('link', $s, ['user_id' => 888001])->assertHasNoTableActionErrors();

        $this->assertSame(888001, $s->fresh()->user_id);
        $this->assertStringContainsString('xodimi sifatida biriktirildingiz', $db->table('outbox')->where('user_id', 888001)->value('text'));

        // the same account moved to another staff member leaves the first one unlinked
        $db->table('users')->insert(['user_id' => 888002, 'first_name' => 'B']);
        $other = Staff::create(['full_name' => 'Boshqa', 'phone' => '+998901110033', 'district_id' => $this->district, 'is_active' => true]);
        Livewire::test(ManageStaff::class)->callTableAction('link', $other, ['user_id' => 888001]);
        $this->assertNull($s->fresh()->user_id);
        $this->assertSame(888001, $other->fresh()->user_id);
    }

    public function test_staff_bot_state_and_a_notice_when_added_while_already_in_the_bot(): void
    {
        $db = DB::connection('bot');
        $db->table('outbox')->delete();
        $db->table('users')->insert(['user_id' => 889001, 'first_name' => 'W', 'phone' => '+998901112233']);
        $s = Staff::create(['full_name' => 'Kutayotgan', 'phone' => '+998901112233', 'district_id' => $this->district, 'is_active' => true]);
        $this->assertSame('waiting', $s->botState());
        $this->assertStringContainsString('/start ni qaytadan bosing', (string) $db->table('outbox')->where('user_id', 889001)->value('text'));

        $absent = Staff::create(['full_name' => 'Kirmagan', 'phone' => '+998901119999', 'district_id' => $this->district, 'is_active' => true]);
        $this->assertSame('absent', $absent->botState());
        $this->assertSame('linked', $this->staff->botState());
        $this->assertSame(1, $db->table('outbox')->count(), 'nobody to tell for a number that is not in the bot');

        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
        Livewire::test(ManageStaff::class)->assertSee('Raqami botda bor, tasdiqlamagan')->assertSee('Bu raqam botda yo‘q')->assertSee('Tasdiqlangan');
    }

    public function test_find_my_place_gives_the_address_the_street_and_nearby_objects(): void
    {
        $db = DB::connection('bot');
        $name = (string) $db->table('streets')->where('id', $this->street)->value('name');
        // the map names the street in Latin with a different spelling: still the same street
        $down = false;
        \Illuminate\Support\Facades\Http::fake(function () use (&$down, $name) { return $down ? \Illuminate\Support\Facades\Http::response('', 500)
            : \Illuminate\Support\Facades\Http::response(['address' => ['road' => \App\Support\Latin::of($name), 'house_number' => '12', 'suburb' => 'Mahalla']]); });
        $here = [41.3111, 69.2797];
        $this->object->forceFill(['found_lat' => 41.3112, 'found_lon' => 69.2798])->save();  // ~14 m away
        $far = DesignObject::create(['street_id' => $this->street, 'shop_name' => 'Uzoqdagi', 'found_lat' => 41.33, 'found_lon' => 69.30]);

        $d = $this->postJson('/tg/api/nearby', ['latitude' => $here[0], 'longitude' => $here[1]], $this->headers())->assertOk()->json();
        $this->assertSame(\App\Support\Latin::of($name).' 12, Mahalla', $d['address']);
        $this->assertSame($this->street, $d['street']['id']);
        $this->assertSame(['Market 24'], array_column($d['objects'], 'shop_name'));  // the far one is not "nearby"
        $this->assertLessThan(30, $d['objects'][0]['distance']);

        // a new business added here keeps the address and the point, and shows up nearby next time
        $o = $this->postJson('/tg/api/streets/'.$this->street.'/objects', ['shop_name' => 'Yangi do‘kon', 'address' => $d['address'],
            'latitude' => $here[0], 'longitude' => $here[1]], $this->headers())->assertOk()->json('object');
        $this->assertSame($d['address'], $o['address']);
        $d2 = $this->postJson('/tg/api/nearby', ['latitude' => $here[0], 'longitude' => $here[1]], $this->headers())->json();
        $this->assertContains('Yangi do‘kon', array_column($d2['objects'], 'shop_name'));
        $this->assertNotContains('Uzoqdagi', array_column($d2['objects'], 'shop_name'));

        // the map service down: no address, still the nearby objects
        \Illuminate\Support\Facades\Cache::flush();
        $down = true;
        $d3 = $this->postJson('/tg/api/nearby', ['latitude' => 41.3111001, 'longitude' => 69.2797001], $this->headers())->assertOk()->json();
        $this->assertNull($d3['address']);
        $this->assertNotEmpty($d3['objects']);
        $this->assertSame($far->street_id, $this->street);
    }

    public function test_staff_must_stand_on_the_street_to_add_or_prove_a_business(): void
    {
        $db = DB::connection('bot');
        // the street runs ~1.1 km north-south along longitude 69.2797
        $db->table('streets')->where('id', $this->street)->update(['start_lat' => 41.3100, 'start_lon' => 69.2797, 'end_lat' => 41.3200, 'end_lon' => 69.2797]);
        \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response(['address' => ['road' => 'Boshqa ko‘cha']]));
        $h = $this->headers();
        $url = "/tg/api/streets/{$this->street}/objects";

        $this->postJson($url, ['shop_name' => 'Uydan'], $h)->assertStatus(422);  // no place at all
        $home = $this->postJson($url, ['shop_name' => 'Uydan', 'latitude' => 41.3150, 'longitude' => 69.2900], $h)->assertStatus(422);  // ~860 m east
        $this->assertStringContainsString('m uzoqdasiz', $home->json('error'));
        $this->assertSame(0, DesignObject::query()->where('shop_name', 'Uydan')->count());

        $ok = $this->postJson($url, ['shop_name' => 'Joyida', 'latitude' => 41.3150, 'longitude' => 69.2810], $h)->assertOk();  // ~110 m
        $id = $ok->json('object.id');

        // proving it later: near where it was added, not from home
        $draft = $this->postJson("/tg/api/objects/$id/inspections", ['kind' => 'removed'], $h)->json('draft.id');
        $db->table('inspections')->where('id', $draft)->update(['step' => 'location']);
        $this->postJson("/tg/api/inspections/$draft/location", ['latitude' => 41.3150, 'longitude' => 69.2900, 'accuracy' => 10], $h)->assertStatus(422);
        $this->postJson("/tg/api/inspections/$draft/location", ['latitude' => 41.3151, 'longitude' => 69.2811, 'accuracy' => 10], $h)->assertOk();

        // "find my place" picks the street by its line
        $d = $this->postJson('/tg/api/nearby', ['latitude' => 41.3150, 'longitude' => 69.2805], $h)->assertOk()->json();
        $this->assertSame($this->street, $d['street']['id']);
        $this->assertSame($this->street, $d['streets'][0]['id']);
    }

    public function test_map_page_draws_street_lines_and_object_points(): void
    {
        $admin = User::factory()->create();
        config(['app.admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
        $db = DB::connection('bot');
        $db->table('streets')->where('id', $this->street)->update(['start_lat' => 41.31, 'start_lon' => 69.27, 'end_lat' => 41.32, 'end_lon' => 69.28]);
        $this->object->forceFill(['found_lat' => 41.315, 'found_lon' => 69.275, 'address' => 'Test manzil 5'])->save();

        $html = $this->get(\App\Filament\Pages\ObjectsMap::getUrl())->assertOk()
            ->assertSee('Dizayn-kod xaritasi')->assertSee('Butun shahar')->getContent();
        $this->assertStringContainsString('[[41.31,69.27],[41.32,69.28]]', $html);   // the street's line
        $this->assertStringContainsString('"at":[41.315,69.275]', $html);            // the object's point
        $this->assertStringContainsString('Market 24', $html);
    }

    public function test_scheduled_reports_go_to_who_wants_them_with_attendance(): void
    {
        $db = DB::connection('bot');
        $db->table('outbox')->delete();
        // the staff member opens the Mini App: today's working day is written
        $this->getJson('/tg/api/me', $this->headers())->assertOk();
        $today = now(config('app.display_timezone'))->toDateString();
        $this->assertSame(1, $db->table('staff_days')->where('staff_id', $this->staff->id)->where('day', $today)->count());
        $this->sendEvidence('removed');

        $db->table('users')->insertOrIgnore([['user_id' => 777101, 'first_name' => 'A'], ['user_id' => 777102, 'first_name' => 'B']]);
        \App\Models\ReportRecipient::create(['user_id' => 777101, 'hourly_report' => true, 'daily_report' => false, 'attendance_report' => true]);
        \App\Models\ReportRecipient::create(['user_id' => 777102, 'hourly_report' => false, 'daily_report' => true, 'attendance_report' => false]);
        $other = Staff::create(['full_name' => 'Ishlamagan', 'phone' => '+998901110099', 'district_id' => $this->otherDistrict, 'is_active' => true]);

        $this->assertSame(1, \App\Services\ScheduledReports::send('hourly'));
        $this->assertStringContainsString('Ishlayotgan xodimlar: 1 / 2', $db->table('outbox')->where('user_id', 777101)->value('text'));

        // 18:00 at the end of the current report day (after 18:00 today that is tomorrow's 18:00)
        $at = Svod::reportDay()->copy()->setTime(18, 0);
        \App\Services\ScheduledReports::send('attendance', $at);
        $text = $db->table('outbox')->where('user_id', 777101)->orderByDesc('id')->value('text');
        $this->assertStringContainsString('Ishlagan: 1 / 2', $text);
        $this->assertStringContainsString('✅ Test Xodim', $text);
        $this->assertStringContainsString('dalil: 1', $text);
        $this->assertStringContainsString('❌ Ishlamagan', $text);
        $this->assertSame(0, $db->table('outbox')->where('user_id', 777102)->count(), 'not who asked for hourly / attendance');

        \App\Services\ScheduledReports::send('daily', $at);
        $this->assertSame(2, $db->table('outbox')->where('user_id', 777102)->whereNotNull('photo')->count());   // svod + bajarilish
        $this->assertSame(0, $db->table('outbox')->where('user_id', 777101)->whereNotNull('photo')->count());

        $this->artisan('reports:send', ['type' => 'hourly'])->assertSuccessful();
        $this->artisan('reports:send', ['type' => 'nope'])->assertFailed();
        $other->delete();
    }
}
