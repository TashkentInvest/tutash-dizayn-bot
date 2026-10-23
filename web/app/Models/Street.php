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
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
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
}
