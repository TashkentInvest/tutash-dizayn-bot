<?php

namespace App\Models;

use App\Support\Labels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A business on a street (table `objects`), added by district staff in the Mini App. How many each street should have
 * comes from the official list (streets.planned_objects). Status changes only through approved inspections:
 * pending -> removed (old sign taken down, "jarayonda") -> installed (design-code sign up, "qilindi").
 */
class DesignObject extends Model
{
    public const PENDING = 'pending';

    public const REMOVED = 'removed';

    public const INSTALLED = 'installed';


    public const STATUS_COLORS = [self::PENDING => 'gray', self::REMOVED => 'warning', self::INSTALLED => 'success'];

    protected $connection = 'bot';

    /** @return array<string, string> status => its name (shared/labels.json: the bot and the Mini App say the same) */
    public static function statuses(): array
    {
        return array_intersect_key(Labels::group('status'), array_flip([self::PENDING, self::REMOVED, self::INSTALLED]));
    }

    protected $table = 'objects';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['street_id' => 'integer', 'created_by' => 'integer', 'is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'object_id');
    }

    public function pendingInspection(): HasOne
    {
        return $this->hasOne(Inspection::class, 'object_id')->whereIn('review', Inspection::OPEN)->latestOfMany();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('objects.is_active', true);
    }

    public function scopeInDistrict(Builder $q, int $districtId): Builder
    {
        return $q->whereIn('street_id', Street::query()->where('district_id', $districtId)->select('id'));
    }

    public function label(): string
    {
        return (string) $this->shop_name;
    }
}
