<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A street of a district (table `streets`). Deleting one removes its files too (they go to the trash folder). */
class Street extends Model
{
    protected $connection = 'bot';

    protected $table = 'streets';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'planned_objects' => 'integer', 'path' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (Street $s) => $s->files()->get()->each->delete());
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StreetFile::class, 'street_id');
    }

    /** businesses on this street (entered by district staff); a street that has them is not deleted */
    public function objects(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DesignObject::class, 'street_id');
    }

    public function hasLine(): bool
    {
        return $this->start_lat !== null && $this->end_lat !== null;
    }

    /** @return list<array{0: float, 1: float}> the street's shape (KMZ import), else the straight start -> end of the list */
    public function points(): array
    {
        if (is_array($this->path) && count($this->path) >= 2) {
            return array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $this->path);
        }

        return $this->hasLine() ? [[(float) $this->start_lat, (float) $this->start_lon], [(float) $this->end_lat, (float) $this->end_lon]] : [];
    }

    /** Metres from a point to the street (its real shape when imported, else start -> end); null when there is no place. */
    public function distanceTo(float $lat, float $lon): ?float
    {
        $pts = $this->points();
        if (! $pts) {
            return null;
        }
        // a flat projection around the point is exact enough over a few kilometres
        $k = 111320.0;
        $kx = $k * cos(deg2rad($lat));
        $best = INF;
        for ($i = 0; $i + 1 < count($pts); $i++) {
            [$ax, $ay] = [($pts[$i][1] - $lon) * $kx, ($pts[$i][0] - $lat) * $k];
            [$bx, $by] = [($pts[$i + 1][1] - $lon) * $kx, ($pts[$i + 1][0] - $lat) * $k];
            [$dx, $dy] = [$bx - $ax, $by - $ay];
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }

        return $best;
    }
}
