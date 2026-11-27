<?php

namespace App\Http\Controllers;

use App\Filament\Pages\ObjectsMap;
use App\Support\Latin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The design-code street lines for other systems (THUDUD SNOS "Umumiy xarita"), as GeoJSON.
 *
 *   GET /api/v1/streets              Authorization: Bearer <INTEGRATION_TOKEN>
 *   GET /api/v1/streets?district_id=3
 *
 * One LineString per street: the real road shape where a KMZ was imported (has_shape = true), else the straight line from
 * its start to its end of the official list. Coordinates are GeoJSON order: [lon, lat].
 */
class StreetLinesApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('bot.integration_token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 401, 'Token noto‘g‘ri');

        $district = $request->integer('district_id') ?: null;
        $features = ObjectsMap::streetLines()
            ->when($district, fn ($c) => $c->where('district_id', $district))
            ->map(fn (array $s) => [
                'type' => 'Feature',
                'id' => $s['id'],
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => array_map(fn ($p) => [(float) $p[1], (float) $p[0]], $s['path'] ?? $s['line']),
                ],
                'properties' => [
                    'id' => $s['id'],
                    'name' => $s['name'],
                    'name_latin' => Latin::of($s['name']),
                    'district_id' => $s['district_id'],          // this system's id (not SNOS's): match by name
                    'district' => $s['district'],
                    'district_latin' => Latin::of($s['district']),  // "Mirzo Ulug‘bek tumani" (‘ U+2018)
                    'length_km' => $s['km'],           // the official list
                    'nonres_objects' => $s['nonres'],  // non-residential objects, official 1st-stage list (fixed)
                    'drawn_km' => $s['drawn_km'],      // the line as drawn
                    'has_shape' => $s['path'] !== null, // false: only start -> end is known
                    'objects_total' => $s['total'],
                    'objects_installed' => $s['installed'],
                    'objects_removed' => $s['removed'],
                    'pct' => $s['pct'],
                    'start' => ['lat' => $s['line'][0][0], 'lon' => $s['line'][0][1]],
                    'end' => ['lat' => $s['line'][1][0], 'lon' => $s['line'][1][1]],
                ],
            ])->values();

        return response()->json([
            'type' => 'FeatureCollection',
            'generated_at' => now()->toIso8601String(),
            'source' => 'dizayn-kod',
            'features' => $features,
        ], 200, ['Cache-Control' => 'private, max-age=300'], JSON_UNESCAPED_UNICODE);
    }
}
