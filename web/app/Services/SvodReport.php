<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Sends the svod pictures to the chosen report recipients: the PNGs go to uploads/reports/, one outbox row per
 * person and picture; the bot delivers them (it polls the outbox every 10 s), so the dashboard needs no bot token.
 */
class SvodReport
{
    /**
     * @param  list<string>  $kinds  keys of SvodImage::KINDS
     * @param  list<int>  $userIds
     * @return int messages queued
     */
    public static function send(array $kinds, array $userIds, CarbonInterface $day): int
    {
        $kinds = array_values(array_intersect(array_keys(SvodImage::KINDS), $kinds));
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (! $kinds || ! $userIds) {
            return 0;
        }
        $dir = rtrim(config('bot.uploads_path'), '/\\').'/reports';
        File::ensureDirectoryExists($dir, 0775);
        $stamp = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $pictures = [];
        foreach ($kinds as $kind) {
            $rel = "reports/$stamp-$kind.png";
            File::put($dir."/$stamp-$kind.png", SvodImage::png($kind, $day));
            @chmod($dir."/$stamp-$kind.png", 0664);  // the bot runs as another user of the same group
            $pictures[] = [$rel, SvodImage::KINDS[$kind].' — '.$day->format('d.m.Y')];
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($userIds as $uid) {
            foreach ($pictures as [$rel, $caption]) {
                $rows[] = ['user_id' => $uid, 'text' => 'Dizayn-kod · '.$caption, 'photo' => $rel, 'created_at' => $now];
            }
        }
        DB::connection('bot')->table('outbox')->insert($rows);
        self::cleanup($dir);

        return count($rows);
    }

    /** Pictures older than 30 days are not needed any more (Telegram keeps its own copy). */
    private static function cleanup(string $dir): void
    {
        foreach (File::files($dir) as $f) {
            if ($f->getMTime() < time() - 30 * 86400) {
                @unlink($f->getPathname());
            }
        }
    }
}
