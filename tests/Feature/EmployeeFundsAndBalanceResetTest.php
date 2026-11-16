<?php

namespace Tests\Feature;

use App\Enums\MoneyAccountKind;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Widgets\BalanceOverview;
use App\Models\EmployeeAdvance;
use App\Models\MoneyAccountReset;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Services\InternalTransfers;
use App\Services\Settings;
use App\Services\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\TestCase;

/** «إضافة إلى العهدة», «تسديد سلفة» from the employees list, and «تصفير الرصيد الكلي». */
class EmployeeFundsAndBalanceResetTest extends TestCase
{
    private function employee(string $name = 'حسن الفني'): User
    {
        $user = User::factory()->create(['name' => $name, 'branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');

        return $user;
    }

    private function treasury(): TreasuryService
    {
        return app(TreasuryService::class);
    }

    public function test_money_is_added_to_an_employees_custody_from_a_box(): void
    {
        $this->treasury()->openingBalance($this->cash(), 100000);
        $hasan = $this->employee();

        Livewire::test(ListEmployees::class)
            ->callTableAction('fundCustody', $hasan, data: ['amount' => 30000, 'money_account_id' => $this->cash()->id, 'notes' => 'لشراء كيبل'])
            ->assertHasNoTableActionErrors()->assertNotified();

        $finance = app(EmployeeFinance::class);
        $this->assertSame([30000, 70000], [$finance->custodyBalance($hasan), $this->treasury()->balance($this->cash())]);
        $row = $finance->statement($hasan)->firstWhere('kind', 'custody_in');
        $this->assertSame([30000, 'تحويل إلى العهدة'], [$row['custody'] ?? null, $row['label']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'custody.funded']);

        // More than the box holds is refused; the box and the custody stay as they are.
        try {
            $finance->fundCustody($hasan, 500000, $this->cash());
            $this->fail('An uncovered amount moved.');
        } catch (BusinessRuleException) {
        }
        $this->assertSame(30000, $finance->custodyBalance($hasan));

        // Only with «تحويل الأموال».
        $this->actingAs($this->employee('موظف آخر'));
        $this->expectException(AuthorizationException::class);
        $finance->fundCustody($hasan, 1000, $this->cash());
    }

    public function test_an_advance_is_repaid_from_the_employees_row(): void
    {
        $this->treasury()->openingBalance($this->cash(), 100000);
        $hasan = $this->employee();
        $nobody = $this->employee('بلا سلفة');
        $advance = app(EmployeeFinance::class)->giveAdvance($hasan, 50000, 'سلفة شخصية', $this->cash());

        Livewire::test(ListEmployees::class)
            ->assertTableActionHidden('repayAdvance', $nobody)
            ->assertTableActionVisible('repayAdvance', $hasan)
            ->callTableAction('repayAdvance', $hasan, data: ['advance_id' => $advance->id, 'amount' => 20000, 'money_account_id' => $this->cash()->id])
            ->assertHasNoTableActionErrors()->assertNotified();

        $this->assertSame(30000, $advance->fresh()->balance);
        $this->assertSame(70000, $this->treasury()->balance($this->cash()));
        $this->assertSame(1, EmployeeAdvance::count());
    }

    public function test_the_total_balance_is_reset_box_by_box_and_each_reset_can_be_undone(): void
    {
        $card = $this->treasury()->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'سوبر كي');
        $this->treasury()->openingBalance($this->cash(), 100000);
        $this->treasury()->openingBalance($card, 20000);
        $hasan = $this->employee();
        app(EmployeeFinance::class)->fundCustody($hasan, 30000, $this->cash());

        Livewire::test(BalanceOverview::class)->assertSee('تصفير الرصيد الكلي')
            ->callAction('resetBalances', data: ['reason' => 'جرد', 'confirm' => 'غلط']);
        $this->assertSame(0, MoneyAccountReset::count(), 'without typing «تصفير» nothing is reset');
        $this->assertSame(70000, $this->treasury()->balance($this->cash()));

        Livewire::test(BalanceOverview::class)
            ->callAction('resetBalances', data: ['reason' => 'جرد نهاية الشهر', 'confirm' => 'تصفير'])->assertNotified();

        $this->assertSame([0, 0, 0], [$this->treasury()->balance($this->cash()), $this->treasury()->balance($card), app(EmployeeFinance::class)->custodyBalance($hasan)]);
        $this->assertSame(3, MoneyAccountReset::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'balances.reset_all']);

        // Nothing left: refused. A reset is undone by a reversal.
        try {
            app(InternalTransfers::class)->resetAllBalances('مرة ثانية');
            $this->fail('A zero total was reset.');
        } catch (BusinessRuleException) {
        }
        $this->treasury()->voidReset(MoneyAccountReset::where('money_account_id', $this->cash()->id)->sole(), 'غلط');
        $this->assertSame(70000, $this->treasury()->balance($this->cash()));

        // The admin only.
        $this->actingAs($this->employee('مو مدير'));
        $this->expectException(AuthorizationException::class);
        app(InternalTransfers::class)->resetAllBalances('محاولة');
    }

    public function test_the_total_balance_tile_can_be_hidden_from_the_settings(): void
    {
        Livewire::test(BalanceOverview::class)->assertSee('عرض الصناديق')->assertSee('تصفير الرصيد الكلي');

        app(Settings::class)->set('dashboard.show_total_balance', false);
        Livewire::test(BalanceOverview::class)->assertDontSee('عرض الصناديق')->assertDontSee('تصفير الرصيد الكلي')
            ->assertSee('الديون الأولية');
        $this->get('/settings')->assertOk()->assertSee('إظهار مربع «الرصيد الكلي» بلوحة التحكم');
    }
}
