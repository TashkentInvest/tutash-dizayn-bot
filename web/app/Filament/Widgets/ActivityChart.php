<?php

namespace App\Filament\Widgets;

use App\Services\BotStats;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class ActivityChart extends ChartWidget
{
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Kunlik faollik';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['7' => 'Oxirgi 7 kun', '30' => 'Oxirgi 30 kun', '90' => 'Oxirgi 90 kun'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $downloads = BotStats::downloadsPerDay($days);
        $registrations = BotStats::registrationsPerDay($days);

        return [
            'datasets' => [
                ['label' => 'Yuklab olishlar', 'data' => $downloads->values()->all(), 'borderColor' => '#f59e0b', 'backgroundColor' => '#f59e0b33', 'fill' => true],
                ['label' => 'Ro‘yxatdan o‘tganlar', 'data' => $registrations->values()->all(), 'borderColor' => '#3b82f6'],
            ],
            'labels' => $downloads->keys()->map(fn ($d) => Carbon::parse($d)->format('d.m'))->all(),
        ];
    }
}
