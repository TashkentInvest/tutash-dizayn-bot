<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** A district shown in the bot's first menu (table `districts`). Deleting one removes its streets and files too. */
class District extends Model
{
    protected $connection = 'bot';

    protected $table = 'districts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (District $d) => $d->streets()->get()->each->delete());
    }

    public function streets(): HasMany
    {
        return $this->hasMany(Street::class, 'district_id');
    }

    public function files(): HasManyThrough
    {
        return $this->hasManyThrough(StreetFile::class, Street::class, 'district_id', 'street_id');
    }
}
