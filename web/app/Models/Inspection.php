<?php

namespace App\Models;

use App\Support\Labels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence a staff member sent from the Mini App for one object (table `inspections`).
 * Steps: photo -> location (where the staff member stands) -> point (the object, picked on the map) -> video -> comment
 * -> submitted. On submit `review` becomes "pending";
 * only an approval in the dashboard changes the object's status. Media paths are relative to BOT_UPLOADS_PATH.
 */
class Inspection extends Model
{
    public const KINDS = [DesignObject::REMOVED => 'Yechildi (eski peshtaxta olindi)', DesignObject::INSTALLED => 'Dizayn-kod bo‘yicha qo‘yildi'];

    public const STEPS = ['photo', 'location', 'point', 'video', 'comment', 'submitted'];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';


    public const REVIEW_COLORS = [self::PENDING => 'warning', self::APPROVED => 'success', self::REJECTED => 'danger'];

    protected $connection = 'bot';

    /** @return array<string, string> review state => its name (shared/labels.json) */
    public static function reviews(): array
    {
        return Labels::group('review');
    }

    protected $table = 'inspections';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'object_id' => 'integer', 'staff_id' => 'integer', 'latitude' => 'float', 'longitude' => 'float',
            'obj_latitude' => 'float', 'obj_longitude' => 'float', 'accuracy' => 'float', 'distance_m' => 'float',
            'created_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
        ];
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(DesignObject::class, 'object_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function isDraft(): bool
    {
        return $this->step !== 'submitted';
    }

    /** Where the staff member stood. */
    public function mapUrl(): ?string
    {
        return $this->latitude !== null ? "https://maps.google.com/?q={$this->latitude},{$this->longitude}" : null;
    }

    /** The object's place as the staff member marked it on the map. */
    public function objectMapUrl(): ?string
    {
        return $this->obj_latitude !== null ? "https://maps.google.com/?q={$this->obj_latitude},{$this->obj_longitude}" : null;
    }
}
