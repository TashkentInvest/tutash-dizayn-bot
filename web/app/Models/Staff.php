<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A district staff member (table `staff`, created by the bot). Works in the Mini App on the objects of their district.
 * `user_id` is set by the bot once the person confirmed their phone with Telegram's contact button.
 */
class Staff extends Model
{
    protected $connection = 'bot';

    protected $table = 'staff';

    public $timestamps = false;

    protected $guarded = ['id', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['district_id' => 'integer', 'user_id' => 'integer', 'is_active' => 'boolean', 'priority_streets' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // added (or phone corrected) while the person already uses the bot: tell them how to confirm
        static::saved(function (Staff $s) {
            if ($s->user_id !== null || ! $s->is_active || ! ($s->wasRecentlyCreated || $s->wasChanged('phone'))) {
                return;
            }
            $uid = BotUser::query()->where('phone', $s->phone)->value('user_id');
            if ($uid) {
                $s->getConnection()->table('outbox')->insert(['user_id' => $uid, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
                    'text' => '👷 Siz '.($s->district?->name ?? 'tuman').' xodimlari ro‘yxatiga qo‘shildingiz. Tasdiqlash uchun /start ni bosing, '
                        .'telefon so‘ralganda “📱 Raqamni yuborish” tugmasini bosing.']);
            }
        });
    }

    /** Where this person is in the bot: linked / in the bot but not confirmed / never opened the bot. */
    public function botState(): string
    {
        if ($this->user_id !== null) {
            return 'linked';
        }

        return BotUser::query()->where('phone', $this->phone)->exists() ? 'waiting' : 'absent';
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'staff_id');
    }

    /** businesses this staff member entered in the Mini App */
    public function createdObjects(): HasMany
    {
        return $this->hasMany(DesignObject::class, 'created_by');
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }
}
