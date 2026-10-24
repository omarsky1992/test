<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\SecondarySales\Pages\ListSecondarySales;
use App\Filament\Widgets\PeriodStats;
use App\Filament\Widgets\SecondarySalesStat;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\SecondarySale;
use App\Models\User;
use App\Services\ReportService;
use App\Services\SecondarySaleService;
use App\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SecondarySalesTest extends TestCase
{
    public function test_a_secondary_sale_is_counted_alone_and_moves_no_money(): void
    {
        $treasury = app(TreasuryService::class);
        $treasury->openingBalance($this->cash(), 100000);
        $before = [FinancialTransaction::count(), LedgerEntry::count(), $treasury->balance($this->cash())];
        $subscriber = $this->account('كرار حسن')->subscriber;

        Livewire::test(ListSecondarySales::class)
            ->callAction('newSecondarySale', data: ['description' => 'شاحن', 'amount' => 15000, 'subscriber_id' => $subscriber->id, 'idempotency_key' => $key = (string) Str::uuid()])
            ->assertNotified('سُجّل البيع الثانوي')
            ->assertSee('كرار حسن')->assertSee('15,000');
        app(SecondarySaleService::class)->record('شاحن', 15000, $subscriber->id, idempotencyKey: $key);
        $this->assertSame(1, SecondarySale::count(), 'the same click twice records once');
        $this->assertMatchesRegularExpression('/^SS-2026-0+1$/', SecondarySale::sole()->number);

        // No posting, no balance, not in the sales, purchases or profit.
        $this->assertSame($before, [FinancialTransaction::count(), LedgerEntry::count(), $treasury->balance($this->cash())]);
        $d = app(ReportService::class)->dashboard(CarbonImmutable::today(), CarbonImmutable::today());
        $this->assertSame([0, 0, 0], [$d['sales'], $d['purchases'], $d['profit']]);
        Livewire::test(PeriodStats::class, ['pageFilters' => ['period' => 'today']])->assertDontSee('15,000');
        Livewire::test(SecondarySalesStat::class)->assertSee('المبيعات الثانوية')->assertSee('15,000');
        Livewire::test(Dashboard::class)->assertSeeLivewire(SecondarySalesStat::class);
    }

    public function test_void_and_reset_keep_everything_and_start_the_total_again(): void
    {
        $sales = app(SecondarySaleService::class);
        $first = $sales->record('سماعة', 10000);
        $sales->record('كيبل', 5000);
        $sales->void($first, 'خطأ');
        $this->assertSame(['total' => 5000, 'count' => 1], $sales->totals());

        $this->travel(1)->minutes();
        Livewire::test(ListSecondarySales::class)->callAction('resetSecondarySales', data: ['notes' => 'نهاية الشهر'])->assertNotified('صُفّرت المبيعات الثانوية');
        $this->assertSame(['total' => 0, 'count' => 0], $sales->totals());
        $this->assertSame(2, SecondarySale::count(), 'nothing is deleted');
        $this->assertSame(5000, AuditLog::where('action', 'secondary_sales.reset')->sole()->new_values['total']);

        $this->travel(1)->minutes();
        $sales->record('ماوس', 7000);
        $this->assertSame(['total' => 7000, 'count' => 1], $sales->totals());
    }

    public function test_employees_record_but_only_with_permission_void_or_reset(): void
    {
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $sale = app(SecondarySaleService::class)->record('شاحن', 15000);
        Livewire::test(ListSecondarySales::class)->assertActionVisible('newSecondarySale')->assertActionHidden('resetSecondarySales')
            ->assertTableActionHidden('void', $sale);
        foreach ([fn () => app(SecondarySaleService::class)->void($sale, 'x'), fn () => app(SecondarySaleService::class)->reset()] as $denied) {
            try {
                $denied();
                $this->fail('Allowed without the permission.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
