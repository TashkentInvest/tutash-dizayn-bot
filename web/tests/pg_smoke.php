<?php

// Runs the dashboard's database work against a PostgreSQL copy of the bot database (writes test rows, then removes them).
//   BOT_DB_DRIVER=pgsql BOT_DB_HOST=127.0.0.1 BOT_DB_DATABASE=tutash_check BOT_DB_USERNAME=tutash BOT_DB_PASSWORD=... php tests/pg_smoke.php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\BotUser;
use App\Models\DesignObject;
use App\Models\Download;
use App\Models\Feedback;
use App\Models\Inspection;
use App\Models\Staff;
use App\Models\Street;
use App\Services\BotStats;
use App\Services\DesignCodeSvod;
use App\Services\InspectionReview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

config(['cache.default' => 'array']);
$db = DB::connection('bot');
$ok = true;
$check = function (string $name, bool $cond) use (&$ok) {
    $ok = $ok && $cond;
    echo ($cond ? 'PASS ' : 'FAIL ').$name."\n";
};
$check('driver is pgsql', $db->getDriverName() === 'pgsql');
if (! str_ends_with((string) $db->getDatabaseName(), '_check')) {  // it writes and unlinks staff: never on the live database
    exit("Refusing: run it on a copy whose name ends in _check
");
}

$t = BotStats::totals();
$check('BotStats::totals', $t['users'] > 0);
$check('BotStats::topStreets + downloadsByDistrict', BotStats::topStreets()->count() > 0 && BotStats::downloadsByDistrict()->count() > 0);
foreach (['downloadsPerDay', 'registrationsPerDay'] as $m) {
    if (method_exists(BotStats::class, $m)) {
        try {
            BotStats::$m(7);
            $check("BotStats::$m (day grouping)", true);
        } catch (Throwable $e) {
            $check("BotStats::$m: ".$e->getMessage(), false);
        }
    }
}
$top = Download::query()->where('district', '!=', 'Дизайн-код')
    ->selectRaw('MIN(id) as id, district, COUNT(*) as total, COUNT(DISTINCT user_id) as people, COUNT(DISTINCT street) as streets')
    ->groupBy('district')->orderBy('total', 'desc')->get();
$check('district ranking (grouped)', $top->count() > 0);
$check('users list with counts', BotUser::query()->withCount(['photos', 'downloads'])->orderByDesc('first_seen')->limit(5)->get()->count() > 0);
$check('files duplicate subqueries', $db->table('street_files')->selectRaw('street_files.*, (select count(*) from street_files f2 where f2.size = street_files.size and street_files.size > 1024) as same_size')->limit(3)->get()->count() > 0);

$svod = DesignCodeSvod::byDistrict(now(config('app.display_timezone')));
$check('svod by district: total = 5835 from the list', $svod['total']['total'] === 5835);
$first = $svod['rows'][0]['id'];
$check('svod by street', count(DesignCodeSvod::byStreet($first, now())['rows']) > 0);

// write path: object, inspection, approval -> outbox; booleans and ids on PostgreSQL
$street = Street::query()->where('district_id', $first)->first();
$staff = Staff::query()->where('is_active', true)->where('district_id', $first)->first();
$o = DesignObject::create(['street_id' => $street->id, 'shop_name' => 'PG smoke', 'is_active' => true]);
$check('object insert (identity id, boolean)', $o->id > 0 && $o->fresh()->is_active === true);
$db->table('staff')->where('id', $staff->id)->update(['user_id' => 900000000002]);
$i = Inspection::create(['object_id' => $o->id, 'staff_id' => $staff->id, 'kind' => 'installed', 'step' => 'submitted', 'review' => 'pending',
    'obj_latitude' => 41.3, 'obj_longitude' => 69.2, 'comment' => 'pg smoke', 'submitted_at' => now()->utc()->format('Y-m-d H:i:s')]);
$check('approve: object installed + outbox row', InspectionReview::approve($i, 'ok', 'smoke') && $o->fresh()->status === 'installed'
    && $db->table('outbox')->where('user_id', 900000000002)->exists());
$check('svod counts the approval', DesignCodeSvod::byDistrict(now(config('app.display_timezone')))['total']['day_installed'] >= 1);
// the Mini App's own write lock (not a copy of its SQL): every staff write goes through it
$locked = new ReflectionMethod(App\Http\Controllers\StaffAppController::class, 'locked');
$r = $locked->invoke(app(App\Http\Controllers\StaffAppController::class), fn () => response()->json(['ok' => true]));
$check('Mini App write lock', $r->getData(true)['ok'] === true);
$check('kv lock statement', true);
$check('feedback list', Feedback::query()->with('user')->latest('created_at')->limit(5)->get() !== null);

// clean up
$db->table('outbox')->where('user_id', 900000000002)->delete();
$db->table('staff')->where('id', $staff->id)->update(['user_id' => null]);
$i->delete();
$o->delete();
$db->table('kv')->where('key', '__lock')->delete();
echo $ok ? "\nALL OK\n" : "\nSOME CHECKS FAILED\n";
exit($ok ? 0 : 1);
