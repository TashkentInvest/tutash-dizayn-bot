<?php

namespace App\Filament\Pages;

use App\Filament\NavGroups;
use App\Filament\Resources\DesignObjects\DesignObjectResource;
use App\Models\DesignObject;
use App\Models\District;
use App\Models\Inspection;
use App\Services\DesignCodeSvod;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * The map: every street of the official list as a line from its start to its end ("dan" / "gacha"), coloured by how far
 * the design code is done there, and every business staff entered, as a point coloured by its status.
 */
class ObjectsMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $navigationLabel = 'Xarita';

    protected static ?string $title = 'Dizayn-kod xaritasi';

    protected static ?string $slug = 'xarita';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.objects-map';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getViewData(): array
    {
        $db = DB::connection('bot');
        $counts = $db->table('objects')->where('is_active', true)
            ->selectRaw("street_id, SUM(CASE WHEN status='removed' THEN 1 ELSE 0 END) AS removed, SUM(CASE WHEN status='installed' THEN 1 ELSE 0 END) AS installed")
            ->groupBy('street_id')->get()->keyBy('street_id');
        $streets = $db->table('streets as s')->join('districts as d', 'd.id', '=', 's.district_id')
            ->whereNotNull('s.start_lat')->whereNotNull('s.end_lat')
            ->get(['s.id', 's.name', 's.district_id', 'd.name as district', 's.planned_objects', 's.start_lat', 's.start_lon', 's.end_lat', 's.end_lon', 's.path'])
            ->map(function ($s) use ($counts) {
                $total = (int) $s->planned_objects;
                $installed = (int) ($counts[$s->id]->installed ?? 0);
                $removed = (int) ($counts[$s->id]->removed ?? 0);

                return [
                    'id' => $s->id, 'name' => $s->name, 'district' => $s->district, 'district_id' => (int) $s->district_id,
                    'line' => [[(float) $s->start_lat, (float) $s->start_lon], [(float) $s->end_lat, (float) $s->end_lon]],
                    // the real shape where a KMZ was imported (it follows the road), else null: the map draws start -> end
                    'path' => ($p = json_decode((string) $s->path, true)) && count($p) >= 2 ? $p : null,
                    'total' => $total, 'installed' => $installed, 'removed' => $removed,
                    'pct' => $total ? round($installed / $total * 100, 1) : 0,
                ];
            })->values();

        $review = Inspection::query()->whereIn('review', Inspection::OPEN)->pluck('object_id')->flip();
        $objects = DesignObject::query()->active()->with('street:id,name,district_id')
            ->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('found_lat'))->get()
            ->map(fn (DesignObject $o) => [
                'name' => $o->shop_name, 'street' => $o->street?->name, 'district_id' => (int) $o->street?->district_id,
                'address' => $o->address, 'status' => isset($review[$o->id]) ? 'review' : $o->status,
                // the approved place when there is one, else where the staff member added it
                'at' => [(float) ($o->latitude ?? $o->found_lat), (float) ($o->longitude ?? $o->found_lon)],
                'exact' => $o->latitude !== null,
                'url' => DesignObjectResource::getUrl('view', ['record' => $o]),
            ])->values();

        return [
            'mapData' => ['streets' => $streets, 'objects' => $objects],
            'districts' => District::query()->orderBy('sort_order')->pluck('name', 'id'),
            'totals' => [
                'streets' => $streets->count(), 'planned' => $streets->sum('total'), 'objects' => $objects->count(),
                'pct' => DesignCodeSvod::pct(($p = $streets->sum('total')) ? round($streets->sum('installed') / $p * 100, 1) : 0),
            ],
        ];
    }
}
