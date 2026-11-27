<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dashboard action that reached many people: who, when, what (audit_logs). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(string $action, array $data = []): self
    {
        return self::create(['user_id' => auth()->id(), 'action' => $action, 'data' => $data]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
