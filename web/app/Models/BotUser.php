<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Telegram user of the bot (table `users`, owned by the bot). Times are stored in UTC. */
class BotUser extends Model
{
    protected $connection = 'bot';

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'registered_at' => 'datetime',
            'consent_at' => 'datetime',
            'approved_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'start_count' => 'integer',
        ];
    }

    public function photos(): HasMany
    {
        return $this->hasMany(UserPhoto::class, 'user_id', 'user_id');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class, 'user_id', 'user_id');
    }

    protected function isRegistered(): Attribute
    {
        return Attribute::get(fn () => $this->registered_at !== null);
    }

    protected function mapUrl(): Attribute
    {
        return Attribute::get(fn () => $this->latitude === null
            ? null
            : "https://www.google.com/maps?q={$this->latitude},{$this->longitude}");
    }
}
