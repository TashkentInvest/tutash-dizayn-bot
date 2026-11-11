<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The address of a point (OpenStreetMap Nominatim, reverse geocoding). Used when a staff member presses "find my place":
 * only the coordinates are sent, no name or account. Cached per ~10 m, so a staff member walking along a street and
 * pressing again costs no new request; a failure just means no address (the staff member types it).
 */
class Geocoder
{
    /** @return array{address: ?string, road: ?string} */
    public static function reverse(float $lat, float $lon): array
    {
        $key = sprintf('geo:%.4f:%.4f', $lat, $lon);

        if ($hit = Cache::get($key)) {
            return $hit;
        }
        $found = (function () use ($lat, $lon) {
            try {
                $res = Http::timeout(5)->withHeaders(['User-Agent' => config('app.name', 'TutashDizaynKod').'/1.0 (design-code staff app)'])
                    ->get(config('telegram.geocoder_url', 'https://nominatim.openstreetmap.org/reverse'), [
                        'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lon, 'zoom' => 18, 'addressdetails' => 1, 'accept-language' => 'uz,ru',
                    ]);
                if (! $res->ok()) {
                    return ['address' => null, 'road' => null];
                }
                $a = $res->json('address') ?? [];
                $road = $a['road'] ?? $a['pedestrian'] ?? $a['street'] ?? null;
                $parts = array_filter([
                    $road ? trim($road.' '.($a['house_number'] ?? '')) : null,
                    $a['neighbourhood'] ?? $a['quarter'] ?? $a['suburb'] ?? null,
                ]);

                return ['address' => $parts ? implode(', ', $parts) : ($res->json('display_name') ?: null), 'road' => $road];
            } catch (\Throwable $e) {
                Log::warning('reverse geocoding failed: '.$e->getMessage());

                return ['address' => null, 'road' => null];
            }
        })();
        if ($found['address'] !== null) {  // a failure is not remembered: the next press tries again
            Cache::put($key, $found, now()->addDays(7));
        }

        return $found;
    }
}
