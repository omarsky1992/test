<?php

namespace App\Filament\Widgets;

use App\Services\ReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Collections, sales and purchases per day. One money axis (all three are dinars).
 * Colors are the validated categorical slots 1–3; the legend is always shown.
 */
class ActivityChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    // Render with the page in one request instead of one request per widget.
    protected static bool $isLazy = false;

    // No auto-refresh every 5 seconds; it loads the small free server and keeps the database awake.
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'المقبوضات والمبيعات والمشتريات يومياً';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        [$from, $to] = ReportService::periodRange($this->pageFilters);
        $daily = app(ReportService::class)->daily($from, $to);

        $series = fn (string $label, array $data, string $color) => [
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color,
            'borderRadius' => 4,
            'borderSkipped' => 'start',
            'maxBarThickness' => 18,
        ];

        return [
            'labels' => $daily['labels'],
            'datasets' => [
                $series('المقبوضات', $daily['collections'], '#2a78d6'),
                $series('المبيعات', $daily['sales'], '#eb6834'),
                $series('المشتريات', $daily['purchases'], '#1baf7a'),
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'top', 'rtl' => true]],
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'grid' => ['color' => 'rgba(120,113,108,0.12)']],
            ],
        ];
    }
}
