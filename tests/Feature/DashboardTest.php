<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BalanceOverview;
use App\Filament\Widgets\CollectionsByMethod;
use App\Filament\Widgets\PeriodStats;
use App\Filament\Widgets\ReceiptsLog;
use App\Models\DeviceType;
use App\Models\ExpenseCategory;
use App\Services\ActivationService;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    private function seedActivity(): void
    {
        $treasury = app(TreasuryService::class);
        $treasury->openingBalance($this->cash(), 500000);
        $treasury->transfer($this->cash(), $this->companyBox(), 200000);

        $account = $this->account();
        app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);   // 35,000 secondary debt
        app(PaymentService::class)->record($this->account('ثاني'), 10000, \App\Enums\PaymentMethod::Cash, $this->cash(), \App\Enums\PaymentType::Advance);
        $treasury->recordExpense(ExpenseCategory::first(), 'راوتر', 40000, $this->cash());
        $treasury->recordDeviceSale(DeviceType::first(), 'راوتر TP-Link', 55000, cost: 40000, into: $this->cash());
    }

    public function test_total_balance_is_money_held_and_excludes_secondary_debts(): void
    {
        $this->seedActivity();

        Livewire::test(BalanceOverview::class)
            ->assertViewHas('total', 490000)
            ->assertViewHas('secondary', 35000)
            ->assertSee('للعرض فقط');
        // cash 325,000 + company 165,000 (200,000 top-up − 35,000 activation) = 490,000; the 35,000 secondary debt is not added.
        $this->assertSame(490000, app(Ledger::class)->balance($this->cash()->ledger_account_id) + app(Ledger::class)->balance($this->companyBox()->ledger_account_id));
    }

    public function test_the_balance_can_be_hidden_and_stays_hidden(): void
    {
        $this->seedActivity();

        Livewire::test(BalanceOverview::class)
            ->assertDontSee('••••••')
            ->call('toggle')
            ->assertSet('hidden', true)
            ->assertSee('••••••');

        Livewire::test(BalanceOverview::class)->assertSet('hidden', true);
    }

    public function test_period_figures_split_sales_and_purchases(): void
    {
        $this->seedActivity();

        $d = app(ReportService::class)->dashboard(CarbonImmutable::today(), CarbonImmutable::today());

        $this->assertSame(10000, $d['collections']);
        $this->assertSame(35000 + 55000, $d['sales']);
        $this->assertSame(15000, $d['device_profit']);
        $this->assertSame(40000 + 200000, $d['purchases']);
    }

    public function test_dashboard_widgets_render_with_the_period_filter(): void
    {
        $this->seedActivity();

        Livewire::test(Dashboard::class)->set('filters.period', 'month')->assertOk();
        Livewire::test(PeriodStats::class, ['pageFilters' => ['period' => 'week']])->assertSee('المبيعات')->assertSee('المشتريات');
        Livewire::test(CollectionsByMethod::class, ['pageFilters' => ['period' => 'today']])->assertSee('نقدي');
        Livewire::test(ReceiptsLog::class, ['pageFilters' => ['period' => 'today']])->assertSee('R-2026-000001');
    }

    public function test_a_device_sold_on_debt_becomes_a_primary_debt(): void
    {
        $account = $this->account();

        app(TreasuryService::class)->recordDeviceSale(DeviceType::first(), 'ONT', 30000, account: $account);

        $this->assertSame(30000, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertSame(30000, (int) $account->debts()->sum('balance'));
    }
}
