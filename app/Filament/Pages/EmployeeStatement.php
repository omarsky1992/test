<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\EmployeeFinance;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One employee's statement: custody collected and handed over, advances and their repayments.
 * An employee can always open their own statement.
 */
class EmployeeStatement extends Page
{
    protected string $view = 'filament.pages.employee-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'الموظفون';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'كشف حساب موظف';

    protected static ?string $slug = 'employee-statement';

    #[Url]
    public string $user = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public function mount(): void
    {
        if (! auth()->user()->can('employees.view')) {
            $this->user = (string) auth()->id();
        }
        $this->user = $this->user ?: (string) auth()->id();
    }

    public function updatedUser(): void
    {
        if (! auth()->user()->can('employees.view')) {
            $this->user = (string) auth()->id();
        }
    }

    protected function getViewData(): array
    {
        $canAll = auth()->user()->can('employees.view');
        $employee = User::find($canAll ? (int) $this->user : auth()->id()) ?? auth()->user();
        $from = filled($this->from) ? CarbonImmutable::parse($this->from) : null;
        $to = filled($this->to) ? CarbonImmutable::parse($this->to) : null;
        $finance = app(EmployeeFinance::class);

        return [
            'employee' => $employee,
            'employees' => $canAll ? User::orderBy('name')->pluck('name', 'id') : collect([$employee->id => $employee->name]),
            'summary' => $finance->summary($employee, $from, $to),
            'rows' => $finance->statement($employee, $from, $to),
            'periodLabel' => $from || $to ? 'للفترة المحددة' : 'منذ البداية',
        ];
    }
}
