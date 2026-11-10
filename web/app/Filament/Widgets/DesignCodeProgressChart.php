<?php

namespace App\Filament\Widgets;

use App\Services\DesignCodeSvod;
use Filament\Widgets\ChartWidget;

/** Design-code progress by district: done / in progress / waiting, against the official object list. */
class DesignCodeProgressChart extends ChartWidget
{
    protected ?string $pollingInterval = '120s';

    protected static ?int $sort = 3;

    protected ?string $heading = 'Dizayn-kod: tumanlar kesimida';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = DesignCodeSvod::byDistrict(now(config('app.display_timezone')))['rows'];
        $short = fn (string $name) => trim(str_replace([' тумани', ' tumani'], '', $name));

        return [
            'datasets' => [
                ['label' => 'Qo‘yildi', 'data' => array_column($rows, 'installed'), 'backgroundColor' => '#22c55e', 'borderRadius' => 3],
                ['label' => 'Yechildi', 'data' => array_column($rows, 'removed'), 'backgroundColor' => '#f59e0b', 'borderRadius' => 3],
                ['label' => 'Kutilmoqda', 'data' => array_column($rows, 'pending'), 'backgroundColor' => '#94a3b866', 'borderRadius' => 3],
            ],
            'labels' => array_map($short, array_column($rows, 'name')),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]]],
            'scales' => ['x' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]], 'y' => ['stacked' => true]],
        ];
    }
}
