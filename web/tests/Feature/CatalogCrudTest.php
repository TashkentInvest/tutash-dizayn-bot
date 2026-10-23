<?php

namespace Tests\Feature;

use App\Filament\Resources\Districts\Pages\CreateDistrict;
use App\Filament\Resources\Files\Pages\ListFiles;
use App\Filament\Resources\Districts\Pages\EditDistrict;
use App\Filament\Resources\Districts\RelationManagers\StreetsRelationManager;
use App\Filament\Resources\Streets\Pages\CreateStreet;
use App\Filament\Resources\Streets\Pages\EditStreet;
use App\Filament\Resources\Streets\RelationManagers\FilesRelationManager;
use App\Models\District;
use App\Models\Street;
use App\Models\StreetFile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Runs against a COPY of the bot database and a temporary data folder, never the real ones.
 */
class CatalogCrudTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = storage_path('framework/testing/catalog-'.uniqid());
        File::makeDirectory($this->tmp.'/data', 0777, true);
        copy(base_path('../bot.db'), $this->tmp.'/bot.db');

        config(['database.connections.bot.database' => $this->tmp.'/bot.db']);
        DB::purge('bot');
        config(['filesystems.disks.bot_data.root' => $this->tmp.'/data']);
        Storage::forgetDisk('bot_data');

        $this->admin = User::factory()->create();
        config(['app.admin_emails' => [$this->admin->email]]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        DB::purge('bot');
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_create_district_street_and_upload_file_then_delete_everything(): void
    {
        Livewire::test(CreateDistrict::class)
            ->fillForm(['name' => 'Test tuman', 'sort_order' => 99, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $district = District::where('name', 'Test tuman')->firstOrFail();

        Livewire::test(CreateStreet::class)
            ->fillForm(['district_id' => $district->id, 'name' => 'Test ko‘cha', 'sort_order' => 1, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors();
        $street = Street::where('name', 'Test ko‘cha')->firstOrFail();
        $this->assertSame($district->id, $street->district_id);

        // upload a 300 KB "pdf"
        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $street, 'pageClass' => EditStreet::class])
            ->callAction(TestAction::make('create')->table(), [
                'path' => UploadedFile::fake()->createWithContent('namuna.pdf', '%PDF-1.4 '.str_repeat('A', 300000)),
            ])->assertHasNoFormErrors();

        $file = StreetFile::where('street_id', $street->id)->firstOrFail();
        $this->assertSame('namuna.pdf', $file->file_name);
        $this->assertGreaterThan(300_000, $file->size);
        $this->assertTrue(Storage::disk('bot_data')->exists($file->path), 'uploaded file is on disk at '.$file->path);
        $this->assertStringStartsWith('dashboard/'.$street->id.'/', $file->path);

        // the bot's view of it: catalog.py reads the same tables, so path must resolve under data/
        $this->assertFileExists($this->tmp.'/data/'.$file->path);

        // rename the street inline through the district's relation manager
        Livewire::test(StreetsRelationManager::class, ['ownerRecord' => $district, 'pageClass' => EditDistrict::class])
            ->callAction(TestAction::make('edit')->table($street), ['name' => 'Yangi nom'])->assertHasNoFormErrors();
        $this->assertSame('Yangi nom', $street->fresh()->name);

        // delete the file: row gone, file moved to _trash (not destroyed)
        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $street, 'pageClass' => EditStreet::class])
            ->callAction(TestAction::make('delete')->table($file));
        $this->assertNull(StreetFile::find($file->id));
        $this->assertFileDoesNotExist($this->tmp.'/data/'.$file->path);
        $this->assertCount(1, File::files($this->tmp.'/data/_trash'));

        // delete the district: cascades to its streets
        Livewire::test(EditDistrict::class, ['record' => $district->getKey()])->callAction('delete');
        $this->assertNull(District::find($district->id));
        $this->assertNull(Street::find($street->id));
    }

    public function test_deleting_a_street_trashes_all_its_files(): void
    {
        $street = Street::whereHas('files')->firstOrFail();
        $n = $street->files()->count();
        // copy its real files into the temp data dir so we can watch them move
        foreach ($street->files as $f) {
            File::ensureDirectoryExists(dirname($this->tmp.'/data/'.$f->path));
            File::put($this->tmp.'/data/'.$f->path, 'x');
        }

        $street->delete();

        $this->assertSame(0, StreetFile::where('street_id', $street->id)->count());
        $this->assertCount($n, File::files($this->tmp.'/data/_trash'));
    }

    public function test_duplicate_filters_find_same_size_and_same_name_files(): void
    {
        $streets = Street::orderBy('id')->limit(4)->get();
        $mk = fn ($street, $name, $size) => StreetFile::create([
            'street_id' => $street->id, 'file_name' => $name, 'path' => "x/{$street->id}/$name",
            'size' => $size, 'sort_order' => 0, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
        $a = $mk($streets[0], 'bir.pdf', 7_777_001);
        $b = $mk($streets[1], 'boshqa-nom.pdf', 7_777_001);   // same size, other name -> renamed copy
        $c = $mk($streets[2], 'takror.pdf', 1_111_111);
        $d = $mk($streets[3], 'takror.pdf', 2_222_222);       // same name, other size
        $e = $mk($streets[0], 'yagona.pdf', 3_333_333);       // unique

        Livewire::test(ListFiles::class)
            ->filterTable('same_size')
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c, $d, $e]);

        Livewire::test(ListFiles::class)
            ->filterTable('same_name')
            ->assertCanSeeTableRecords([$c, $d])->assertCanNotSeeTableRecords([$a, $b, $e]);

        Livewire::test(ListFiles::class)
            ->filterTable('duplicates')
            ->assertCanSeeTableRecords([$a, $b, $c, $d])->assertCanNotSeeTableRecords([$e]);
    }

    public function test_same_file_name_uploaded_twice_never_shares_a_path(): void
    {
        $street = Street::orderBy('id')->firstOrFail();
        $upload = fn () => Livewire::test(FilesRelationManager::class, ['ownerRecord' => $street, 'pageClass' => EditStreet::class])
            ->callAction(TestAction::make('create')->table(), [
                'path' => UploadedFile::fake()->createWithContent('bir-xil.pdf', '%PDF-1.4 '.str_repeat('Z', 5000)),
            ]);
        $upload();
        $upload();

        $rows = StreetFile::where('street_id', $street->id)->where('file_name', 'bir-xil.pdf')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->path, $rows[1]->path, 'each upload is stored under its own unique name');
        $this->assertTrue(Storage::disk('bot_data')->exists($rows[0]->path) && Storage::disk('bot_data')->exists($rows[1]->path));

        $rows[0]->delete();  // deleting one must not touch the other's file
        $this->assertTrue(Storage::disk('bot_data')->exists($rows[1]->path));
    }

    public function test_only_pdf_uploads_are_accepted(): void
    {
        $street = Street::orderBy('id')->firstOrFail();
        Livewire::test(FilesRelationManager::class, ['ownerRecord' => $street, 'pageClass' => EditStreet::class])
            ->callAction(TestAction::make('create')->table(), [
                'path' => UploadedFile::fake()->createWithContent('x.html', '<script>alert(1)</script>'),
            ])->assertHasFormErrors(['path']);
        $this->assertSame(0, StreetFile::where('file_name', 'x.html')->count());
    }

    public function test_panel_and_file_routes_are_closed_to_non_admins(): void
    {
        $other = User::factory()->create();  // a valid login, but not in ADMIN_EMAILS
        $this->actingAs($other);
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/districts')->assertForbidden();
        $file = StreetFile::firstOrFail();
        $this->get(route('bot-file', $file))->assertForbidden();
        $this->get(route('bot-photo', 1))->assertForbidden();

        $this->actingAs($this->admin);
        $this->get('/admin')->assertOk();
    }

    public function test_file_route_never_serves_active_content_inline(): void
    {
        $street = Street::orderBy('id')->firstOrFail();
        Storage::disk('bot_data')->put('x/evil.html', '<script>alert(1)</script>');
        $f = StreetFile::create(['street_id' => $street->id, 'file_name' => 'evil.html', 'path' => 'x/evil.html', 'size' => 25,
            'sort_order' => 0, 'created_at' => now()->utc()->format('Y-m-d H:i:s')]);

        $r = $this->get(route('bot-file', $f));
        $r->assertOk();
        $this->assertStringStartsWith('attachment', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('sandbox', $r->headers->get('Content-Security-Policy'));
        $this->assertSame('application/octet-stream', $r->headers->get('Content-Type'));
    }

    public function test_district_contact_phone_is_saved_normalised_and_bad_numbers_are_refused(): void
    {
        $district = District::orderBy('id')->firstOrFail();

        Livewire::test(EditDistrict::class, ['record' => $district->getKey()])
            ->fillForm(['phone' => '99-201 22 72'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('+998992012272', $district->fresh()->phone);

        Livewire::test(EditDistrict::class, ['record' => $district->getKey()])
            ->fillForm(['phone' => '12345'])->call('save')->assertHasFormErrors(['phone']);
        $this->assertSame('+998992012272', $district->fresh()->phone, 'a refused value is not stored');

        Livewire::test(EditDistrict::class, ['record' => $district->getKey()])
            ->fillForm(['phone' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($district->fresh()->phone, 'empty means: show no contact line');

        $this->assertSame('+998 99 201 22 72', \App\Support\Phone::pretty('+998992012272'));
    }
}
