<?php

namespace App\Console\Commands;

use App\Services\ScheduledReports;
use Illuminate\Console\Command;

/** php artisan reports:send hourly|daily|attendance - what the scheduler runs (routes/console.php); also by hand. */
class SendReports extends Command
{
    protected $signature = 'reports:send {type : hourly, daily or attendance}';

    protected $description = 'Queue a scheduled report for its recipients (the bot delivers it)';

    public function handle(): int
    {
        $type = (string) $this->argument('type');
        if (! isset(ScheduledReports::TYPES[$type])) {
            $this->error('type: '.implode(', ', array_keys(ScheduledReports::TYPES)));

            return self::INVALID;
        }
        $n = ScheduledReports::send($type);
        $this->info(ScheduledReports::TYPES[$type].": $n ta xabar navbatga qo‘yildi");

        return self::SUCCESS;
    }
}
