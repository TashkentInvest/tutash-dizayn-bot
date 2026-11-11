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
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'planned_objects' => 'integer'];
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

    /** Metres from a point to the street's line (start -> end of the official list); null when the list gives no place. */
    public function distanceTo(float $lat, float $lon): ?float
    {
        if (! $this->hasLine()) {
            return null;
        }
        // a flat projection around the point is exact enough over a few kilometres
        $k = 111320.0;
        $kx = $k * cos(deg2rad($lat));
        [$ax, $ay] = [((float) $this->start_lon - $lon) * $kx, ((float) $this->start_lat - $lat) * $k];
        [$bx, $by] = [((float) $this->end_lon - $lon) * $kx, ((float) $this->end_lat - $lat) * $k];
        [$dx, $dy] = [$bx - $ax, $by - $ay];
        $len2 = $dx * $dx + $dy * $dy;
        $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;

        return hypot($ax + $t * $dx, $ay + $t * $dy);
    }
}
