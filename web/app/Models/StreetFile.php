<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file the bot sends for a street (table `street_files`). `path` is relative to BOT_DATA_PATH.
 * Deleting the row never destroys the file: it is moved to `_trash/` so a mistake can be undone by hand.
 */
class StreetFile extends Model
{
    protected $connection = 'bot';

    protected $table = 'street_files';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'size' => 'integer', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(function (StreetFile $f) {
            $disk = Storage::disk('bot_data');
            // never trash a file another row still points to
            $shared = static::where('path', $f->path)->where('id', '!=', $f->id)->exists();
            if (! $shared && $f->path && $disk->exists($f->path)) {
                $disk->makeDirectory('_trash');
                $target = '_trash/'.now()->format('Ymd_His').'_'.$f->id.'_'.basename($f->path);
                $disk->move($f->path, $target);
            }
        });
    }

    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class, 'street_id');
    }
}
