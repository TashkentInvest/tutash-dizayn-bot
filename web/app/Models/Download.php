<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One file/link delivered to a user (table `downloads`, owned by the bot). */
class Download extends Model
{
    protected $connection = 'bot';

    protected $table = 'downloads';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }
}
