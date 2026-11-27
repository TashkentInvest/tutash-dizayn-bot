<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/** A video's frame and length (ffprobe), so Telegram shows it as filmed: without them a phone video comes out stretched. */
class MediaProbe
{
    /** @return array{width?: int, height?: int, duration?: int} empty when ffprobe is missing or the file is unreadable */
    public static function video(string $file): array
    {
        $r = Process::timeout(20)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:stream_tags=rotate:stream_side_data=rotation:format=duration', '-of', 'json', $file]);
        $j = $r->successful() ? json_decode($r->output(), true) : null;
        $s = $j['streams'][0] ?? [];
        if (empty($s['width']) || empty($s['height'])) {
            return [];
        }
        // filmed upright on a phone: the frame is stored sideways with a rotation, width and height swap
        $rotate = abs((int) ($s['tags']['rotate'] ?? ($s['side_data_list'][0]['rotation'] ?? 0)));
        [$w, $h] = in_array($rotate, [90, 270], true) ? [$s['height'], $s['width']] : [$s['width'], $s['height']];

        return array_filter(['width' => (int) $w, 'height' => (int) $h, 'duration' => (int) round((float) ($j['format']['duration'] ?? 0))]);
    }
}
