<?php

namespace App\Filament\Pages;

use App\Services\EmployeeFinance;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

class EmployeeReports extends Page
{
    protected string $view = 'filament.pages.employee-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'الموظفون';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'تقارير الموظفين';

    protected static ?string $slug = 'employee-reports';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->user()->can('employees.view');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    protected function getViewData(): array
    {
        $from = CarbonImmutable::parse($this->from ?: now()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($this->to ?: now()->toDateString());

        return ['report' => app(EmployeeFinance::class)->report($from, $to->lessThan($from) ? $from : $to)];
    }
}
