<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who gets the design-code report pictures in Telegram (table `report_recipients`, created by the bot). */
class ReportRecipient extends Model
{
    protected $connection = 'bot';

    protected $table = 'report_recipients';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['created_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'created_at' => 'datetime'];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'user_id', 'user_id');
    }

    /** "Ism Familiya · lavozim" for the send dialog */
    public function title(): string
    {
        return trim(($this->botUser?->full_name ?: $this->botUser?->first_name ?: 'ID '.$this->user_id).($this->label ? ' · '.$this->label : ''));
    }
}
