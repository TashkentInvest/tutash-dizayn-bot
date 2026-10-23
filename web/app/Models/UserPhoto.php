<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An object photo uploaded at registration (table `user_photos`); `path` is relative to BOT_UPLOADS_PATH. */
class UserPhoto extends Model
{
    protected $connection = 'bot';

    protected $table = 'user_photos';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }

    public function getUrlAttribute(): string
    {
        return route('bot-photo', $this);
    }
}
