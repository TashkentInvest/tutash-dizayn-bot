<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A location check a staff member failed in the Mini App (dashboard database; the related rows live in the bot's). */
class LocationFailure extends Model
{
    public const KINDS = [
        'add_object' => 'Obyekt qo‘shish',
        'evidence' => 'Dalil: joylashuv',
        'point' => 'Dalil: xaritadagi nuqta',
    ];

    public const REASONS = [
        'off_street' => 'Ko‘chadan uzoqda',
        'too_far' => 'Obyektdan uzoqda',
        'point_far' => 'Nuqta uzoqda belgilangan',
        'accuracy' => 'GPS aniqligi past',
    ];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float', 'accuracy' => 'float',
            'target_lat' => 'float', 'target_lon' => 'float', 'distance_m' => 'float'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(DesignObject::class, 'object_id');
    }

    /** Never lets a logging problem break the staff member's request. */
    public static function record(array $row): void
    {
        try {
            // the same refusal sent twice in a few seconds (a double press) is one row
            $dup = static::query()->where('staff_id', $row['staff_id'])->where('reason', $row['reason'])->where('kind', $row['kind'])
                ->where('object_id', $row['object_id'] ?? null)->where('created_at', '>=', now()->subSeconds(20))
                ->where('latitude', $row['latitude'])->where('longitude', $row['longitude'])->exists();
            if (! $dup) {
                static::create($row);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
