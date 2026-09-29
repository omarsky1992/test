<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\ReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

class DailyReport extends Page
{
    protected string $view = 'filament.pages.daily-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'التقرير اليومي';

    protected static ?string $title = 'التقرير اليومي';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $user = '';

    public static function canAccess(): bool
    {
        return auth()->user()->can('reports.view');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->toDateString();
        $this->to = $this->to ?: $this->from;
    }

    protected function getViewData(): array
    {
        $reports = app(ReportService::class);
        $from = CarbonImmutable::parse($this->from ?: now()->toDateString());
        $to = CarbonImmutable::parse($this->to ?: $this->from);

        return [
            'report' => $reports->period($from, $to->lessThan($from) ? $from : $to, $this->user !== '' ? (int) $this->user : null),
            'balances' => $reports->balances(),
            'users' => User::orderBy('name')->pluck('name', 'id'),
        ];
    }
}
