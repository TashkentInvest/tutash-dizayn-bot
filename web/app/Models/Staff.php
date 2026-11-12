<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A district staff member (table `staff`, created by the bot). Works in the Mini App on the streets assigned to them
 * ("Biriktirilgan ko'chalar"), or on every street of their district when none are assigned.
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
                    // short on purpose: after /start the bot itself asks for the contact button (the number is on the staff list)
                    'text' => '👷 Iltimos, /start ni qaytadan bosing.']);
            }
        });
    }

    /** @return list<int>|null the streets this person works on; null = every street of the district (none assigned) */
    public function streetIds(): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $this->priority_streets ?? [])));
        if (! $ids) {
            return null;
        }
        // only those still in their district (it may have been changed since): none left = the whole district again
        $ids = Street::query()->where('district_id', $this->district_id)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();

        return $ids ?: null;
    }

    /** The streets this person may see and work on: in the Mini App list, "find my place", adding businesses, evidence. */
    public function streetsQuery(): Builder
    {
        $q = Street::query()->where('district_id', $this->district_id);
        $ids = $this->streetIds();

        return $ids === null ? $q : $q->whereIn('id', $ids);
    }

    public function worksOn(int $streetId): bool
    {
        return $this->streetsQuery()->whereKey($streetId)->exists();
    }

    /**
     * The personal invite link: t.me/<bot>?start=s_<token>. Opening it links this staff member to whoever opens it,
     * whatever number they joined with. Valid for 7 days, works once; a still valid one is reused.
     *
     * @return array{link: string, expires: \Carbon\CarbonInterface}
     */
    public function inviteLink(bool $renew = false): array
    {
        $valid = $this->invite_token && $this->invite_expires && $this->invite_expires > now()->utc()->format('Y-m-d H:i:s');
        if ($renew || ! $valid) {
            $this->forceFill([
                'invite_token' => \Illuminate\Support\Str::random(24),
                'invite_expires' => now()->utc()->addDays(7)->format('Y-m-d H:i:s'),
            ])->saveQuietly();
        }

        return ['link' => 'https://t.me/'.self::botUsername().'?start=s_'.$this->invite_token,
            'expires' => \Carbon\Carbon::parse($this->invite_expires, 'UTC')->setTimezone(config('app.display_timezone'))];
    }

    public static function botUsername(): string
    {
        if ($u = config('telegram.bot_username')) {
            return ltrim($u, '@');
        }

        return \Illuminate\Support\Facades\Cache::rememberForever('tg-bot-username', function () {
            $token = config('telegram.bot_token');
            $me = $token ? \Illuminate\Support\Facades\Http::timeout(5)->get("https://api.telegram.org/bot$token/getMe")->json('result.username') : null;

            return $me ?: 'DizaynKodBot';
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
