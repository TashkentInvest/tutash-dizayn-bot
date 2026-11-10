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
        copy(base_path('../bot.db'), $this->tmp.'/bot.db');
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
        $new = $this->postJson("/tg/api/streets/{$this->street}/objects", ['shop_name' => 'Gul do‘koni', 'stir' => '123456789', 'owner_phone' => '90 123 45 67'], $h)->assertOk();
        $this->postJson("/tg/api/streets/{$this->street}/objects", ['shop_name' => ' gul DO‘KONI '], $h)->assertOk()->assertJsonPath('already', true);
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
        $day = now(config('app.display_timezone'));
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
}
