<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A citizen's request sent through the bot (table `feedback`, owned by the bot). Times are stored in UTC.
 * The dashboard writes `answer`; the bot delivers it and sets `answer_sent_at` (and `answer_error` if it could not).
 */
class Feedback extends Model
{
    public const LEVELS = ['high' => 'Yuqori', 'medium' => 'O‘rta', 'low' => 'Past'];

    public const LEVEL_COLORS = ['high' => 'danger', 'medium' => 'warning', 'low' => 'success'];

    public const STATUSES = ['new' => 'Yangi', 'answered' => 'Javob yuborilmoqda', 'delivered' => 'Javob yetkazildi', 'failed' => 'Yetkazilmadi'];

    public const STATUS_COLORS = ['new' => 'danger', 'answered' => 'warning', 'delivered' => 'success', 'failed' => 'gray'];

    protected $connection = 'bot';

    protected $table = 'feedback';

    public $timestamps = false;

    protected $guarded = ['id', 'user_id', 'level', 'text', 'created_at', 'answer_sent_at', 'answer_error'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'answered_at' => 'datetime',
            'answer_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }

    public function status(): string
    {
        return match (true) {
            $this->answer === null => 'new',
            $this->answer_sent_at === null => 'answered',
            $this->answer_error !== null => 'failed',
            default => 'delivered',
        };
    }
}
