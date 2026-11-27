<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A post from the dashboard ("Post yuborish"): a picture or video and a text. The bot sends it (bot/posts.py), keeps
 * each message id and takes it back on request. Telegram does not tell a bot who saw a message in a private chat:
 * "seen" here is "pressed Tanishdim, or used the bot after the post came" — at least that many.
 */
class Post extends Model
{
    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const MEDIA = ['photo' => 'Rasm', 'video' => 'Video'];

    /** Telegram lets a bot delete its message for this long */
    public const RECALL_HOURS = 48;

    protected $connection = 'bot';

    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'audience' => 'array', 'ack_button' => 'boolean', 'total' => 'integer', 'delivered' => 'integer', 'failed' => 'integer',
            'recalled' => 'integer', 'recall_failed' => 'integer', 'created_by' => 'integer',
            'created_at' => 'datetime', 'finished_at' => 'datetime', 'recalled_at' => 'datetime', 'recall_finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Post $p) {
            $p->deliveries()->delete();  // SQLite has no cascade here
            if ($p->media_path) {
                Storage::disk('bot_uploads')->delete($p->media_path);
            }
        });
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PostDelivery::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Hours left to take the post back from the chats (0: too late). */
    public function recallHoursLeft(): int
    {
        return max(0, (int) floor(now()->diffInHours($this->created_at->copy()->addHours(self::RECALL_HOURS), false)));
    }

    /** "Ro'yxatdan o'chirish" only once nothing could be lost: taken back from the bot already, or too late to. */
    public function canBeForgotten(): bool
    {
        return $this->recalled_at !== null || $this->recallHoursLeft() === 0 || ($this->status === self::SENT && ! $this->delivered);
    }

    /**
     * @return array{total: int, delivered: int, failed: int, ack: int, active: int, seen: int}
     */
    public function stats(): array
    {
        $sent = fn () => PostDelivery::query()->where('post_deliveries.post_id', $this->id)->where('post_deliveries.status', PostDelivery::SENT);

        return [
            'total' => (int) $this->total, 'delivered' => (int) $this->delivered, 'failed' => (int) $this->failed,
            'ack' => $sent()->whereNotNull('post_deliveries.ack_at')->count(),
            'active' => $sent()->where(PostDelivery::usedBotAfter(...))->count(),
            'seen' => $sent()->where(fn ($query) => $query->whereNotNull('post_deliveries.ack_at')->orWhere(PostDelivery::usedBotAfter(...)))->count(),
        ];
    }

    /** The start of the text, or the kind of media. */
    public function title(): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $this->caption));

        return $t !== '' ? Str::limit($t, 60) : (self::MEDIA[$this->media_type] ?? 'Post');
    }
}
