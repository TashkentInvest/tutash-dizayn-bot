<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One business on a street: one page (after the cover) of the street's concept PDF (table `objects`, filled by the bot).
 * Status changes only through approved inspections: pending -> removed (old sign taken down, "jarayonda")
 * -> installed (design-code sign up, "qilindi").
 */
class DesignObject extends Model
{
    public const PENDING = 'pending';

    public const REMOVED = 'removed';

    public const INSTALLED = 'installed';

    public const STATUSES = [self::PENDING => 'Kutilmoqda', self::REMOVED => 'Yechildi (jarayonda)', self::INSTALLED => 'Qo‘yildi (qilindi)'];

    public const STATUS_COLORS = [self::PENDING => 'gray', self::REMOVED => 'warning', self::INSTALLED => 'success'];

    protected $connection = 'bot';

    protected $table = 'objects';

    public $timestamps = false;

    protected $guarded = ['id', 'street_id', 'file_id', 'page', 'image', 'gone', 'page_hash'];

    protected function casts(): array
    {
        return ['street_id' => 'integer', 'file_id' => 'integer', 'is_active' => 'boolean', 'gone' => 'boolean', 'latitude' => 'float', 'longitude' => 'float', 'page' => 'integer'];
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StreetFile::class, 'file_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'object_id');
    }

    public function pendingInspection(): HasOne
    {
        return $this->hasOne(Inspection::class, 'object_id')->where('review', Inspection::PENDING)->latestOfMany();
    }

    /** Number of the object on its street, as staff and admins call it: page 2 of the PDF is object 1. */
    public function number(): int
    {
        return $this->page - 1;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('objects.is_active', true)->where('objects.gone', 0); // admin did not switch it off, and it is still in a PDF
    }

    public function scopeInDistrict(Builder $q, int $districtId): Builder
    {
        return $q->whereIn('street_id', Street::query()->where('district_id', $districtId)->select('id'));
    }

    public function label(): string
    {
        return '№'.$this->number().($this->shop_name ? ' · '.$this->shop_name : '');
    }
}
