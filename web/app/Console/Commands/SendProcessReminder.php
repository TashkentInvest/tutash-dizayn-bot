<?php

namespace App\Console\Commands;

use App\Services\ProcessReminder;
use Illuminate\Console\Command;

/** php artisan staff:process-reminder - the 09:00 reminder of the objects "Jarayonda" (routes/console.php). */
class SendProcessReminder extends Command
{
    protected $signature = 'staff:process-reminder';

    protected $description = 'Tell each staff member their objects in process (what to fix) through the bot';

    public function handle(): int
    {
        $this->info(ProcessReminder::send().' ta xodimga yuborildi');

        return self::SUCCESS;
    }
}
