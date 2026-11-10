<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/** Everything on one page: today's numbers, activity, design-code progress, staff at work, what waits for an admin. */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Bosh sahifa';

    protected static ?string $navigationLabel = 'Bosh sahifa';

    public function getColumns(): int|array
    {
        return ['md' => 1, 'lg' => 2];
    }
}
