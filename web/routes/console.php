<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reports for the people in Sozlamalar -> Hisobot oluvchilar (Tashkent time). The server runs `schedule:run` every minute.
use Illuminate\Support\Facades\Schedule;

$tz = config('app.display_timezone', 'Asia/Tashkent');
Schedule::command('reports:send hourly')->timezone($tz)->hourlyAt(0)->between('9:00', '17:59')->withoutOverlapping();
Schedule::command('reports:send daily')->timezone($tz)->dailyAt('18:00')->withoutOverlapping();
Schedule::command('reports:send attendance')->timezone($tz)->dailyAt('18:01')->withoutOverlapping();
