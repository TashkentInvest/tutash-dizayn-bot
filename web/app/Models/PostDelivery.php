<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person a post went (or goes) to. Written by the dashboard as "pending", then by the bot. */
class PostDelivery extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $connection = 'bot';

    protected $table = 'post_deliveries';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'post_id' => 'integer', 'user_id' => 'integer', 'message_id' => 'integer',
            'sent_at' => 'datetime', 'ack_at' => 'datetime', 'recalled_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'user_id', 'user_id');
    }

    /** used the bot after the post came (users.last_seen is written on every update, at most once a minute) */
    public static function usedBotAfter(Builder $query): void
    {
        $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('users')
            ->whereColumn('users.user_id', 'post_deliveries.user_id')
            ->whereColumn('users.last_seen', '>', 'post_deliveries.sent_at'));
    }
}
