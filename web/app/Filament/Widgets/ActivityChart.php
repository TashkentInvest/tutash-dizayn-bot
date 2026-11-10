<?php

namespace App\Filament\Widgets;

use App\Services\BotStats;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/** Daily activity of citizens and district staff on one time axis. */
class ActivityChart extends ChartWidget
{
    protected ?string $pollingInterval = '120s';

    protected static ?int $sort = 2;

    protected ?string $heading = 'Kunlik faollik';

    protected ?string $maxHeight = '280px';

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
        $line = fn (string $label, $series, string $color, bool $fill = false) => [
            'label' => $label, 'data' => $series->values()->all(), 'borderColor' => $color, 'backgroundColor' => $color.'26',
            'fill' => $fill, 'tension' => 0.3, 'pointRadius' => 0, 'borderWidth' => 2,
        ];

        return [
            'datasets' => [
                $line('Yuklab olishlar', $downloads, '#f59e0b', true),
                $line('Yangi foydalanuvchilar', BotStats::newUsersPerDay($days), '#3b82f6'),
                $line('Murojaatlar', BotStats::feedbackPerDay($days), '#ef4444'),
                $line('Kiritilgan tadbirkorlar', BotStats::objectsPerDay($days), '#10b981'),
                $line('Xodim dalillari', BotStats::inspectionsPerDay($days), '#8b5cf6'),
            ],
            'labels' => $downloads->keys()->map(fn ($d) => Carbon::parse($d)->format('d.m'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 10, 'usePointStyle' => true]]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
