<?php

namespace Tests\Feature;

use App\Enums\AdvanceStatus;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentType;
use App\Filament\Pages\EmployeeReports;
use App\Filament\Pages\EmployeeStatement;
use App\Filament\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\AuditLog;
use App\Models\EmployeeAdvance;
use App\Models\PaymentMethodType;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\TreasuryService;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeFinanceTest extends TestCase
{
    private User $ahmed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahmed = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $this->ahmed->assignRole('employee');
    }

    private function finance(): EmployeeFinance
    {
        return app(EmployeeFinance::class);
    }

    /** Ahmed collects cash from subscribers: it lands in his custody. */
    private function ahmedCollects(int $amount): void
    {
        $this->actingAs($this->ahmed);
        $cash = PaymentMethodType::where('code', 'cash')->first();
        app(PaymentService::class)->record($this->account(username: 'u'.random_int(1000, 99999)), type: PaymentType::Advance, lines: [
            ['method' => $cash, 'money_account' => $this->finance()->custodyAccount($this->ahmed), 'amount' => $amount],
        ]);
        $this->actingAs($this->admin);
    }

    public function test_an_employees_collection_is_held_as_custody(): void
    {
        $this->ahmedCollects(300000);
        $this->ahmedCollects(200000);

        $this->assertSame(500000, $this->finance()->custodyBalance($this->ahmed));
        $summary = $this->finance()->summary($this->ahmed);
        $this->assertSame(500000, $summary['collections']);
        $this->assertSame(0, $summary['handed_over']);
        // The company cash box has not received it yet.
        $this->assertSame(0, app(TreasuryService::class)->balance($this->cash()));
    }

    public function test_handing_custody_to_the_cash_box_moves_the_money(): void
    {
        $this->ahmedCollects(500000);

        $transfer = $this->finance()->handOver($this->ahmed, 500000, $this->cash());

        $this->assertSame(0, $this->finance()->custodyBalance($this->ahmed));
        $this->assertSame(500000, app(TreasuryService::class)->balance($this->cash()));
        $this->assertSame($this->admin->id, $transfer->created_by);
        $this->assertNotNull($transfer->transferred_at);

        $statement = $this->finance()->statement($this->ahmed);
        $this->assertSame(['handover', 'collection'], $statement->pluck('kind')->all());
        $this->assertSame(-500000, $statement->first()['custody']);
        $this->assertSame($this->admin->name, $statement->first()['by']);
        $this->assertSame(500000, $this->finance()->summary($this->ahmed)['handed_over']);

        $log = AuditLog::where('action', 'custody.settled')->sole();
        $this->assertSame(500000, $log->old_values['custody']);
        $this->assertSame(0, $log->new_values['custody']);
    }

    public function test_handing_over_more_than_the_custody_is_refused(): void
    {
        $this->ahmedCollects(100000);

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $this->finance()->handOver($this->ahmed, 150000, $this->cash());
    }

    public function test_an_advance_is_repaid_partly_then_fully_and_never_deleted(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 1000000);

        $advance = $this->finance()->giveAdvance($this->ahmed, 500000, 'ظرف عائلي', $this->cash(), 'تفاصيل', 'ملاحظة');
        $this->assertSame(AdvanceStatus::Unpaid, $advance->status);
        $this->assertSame(500000, app(TreasuryService::class)->balance($this->cash()));
        $this->assertSame(500000, $this->balanceOf(Ledger::EMPLOYEE_ADVANCES));

        $this->finance()->repay($advance, 200000, $this->cash(), 'دفعة أولى');
        $advance->refresh();
        $this->assertSame(AdvanceStatus::Partial, $advance->status);
        $this->assertSame(300000, $advance->balance);

        $this->finance()->repay($advance, 300000, $this->cash());
        $advance->refresh();
        $this->assertSame(AdvanceStatus::Paid, $advance->status);
        $this->assertSame(0, $advance->balance);
        $this->assertSame(500000, $advance->amount);
        $this->assertSame(2, $advance->repayments()->count());
        $this->assertSame(1000000, app(TreasuryService::class)->balance($this->cash()));
        $this->assertSame(0, $this->balanceOf(Ledger::EMPLOYEE_ADVANCES));
        $this->assertSame(2, AuditLog::where('action', 'advance.repaid')->count());
    }

    public function test_repaying_more_than_the_remainder_is_refused(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 1000000);
        $advance = $this->finance()->giveAdvance($this->ahmed, 100000, 'سلفة', $this->cash());

        $this->expectException(\App\Exceptions\BusinessRuleException::class);
        $this->finance()->repay($advance, 150000, $this->cash());
    }

    public function test_custody_and_advances_stay_separate(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 1000000);
        $this->ahmedCollects(500000);
        $this->finance()->giveAdvance($this->ahmed, 100000, 'سلفة', $this->cash());

        $summary = $this->finance()->summary($this->ahmed);
        $this->assertSame(500000, $summary['custody']);
        $this->assertSame(100000, $summary['advances_remaining']);
        // Handing over custody does not touch the advance, and repaying the advance does not touch custody.
        $this->finance()->handOver($this->ahmed, 500000, $this->cash());
        $this->assertSame(100000, $this->finance()->summary($this->ahmed)['advances_remaining']);
        $this->finance()->repay(EmployeeAdvance::sole(), 100000, $this->cash());
        $this->assertSame(0, $this->finance()->summary($this->ahmed)['custody']);
        $this->assertSame(1500000, app(TreasuryService::class)->balance($this->cash()));
        $kinds = $this->finance()->statement($this->ahmed)->pluck('kind')->all();
        $this->assertContains('advance', $kinds);
        $this->assertContains('repayment', $kinds);
        $this->assertContains('handover', $kinds);
    }

    public function test_employee_screens_open_and_actions_work(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 1000000);
        $this->ahmedCollects(250000);

        $this->get('/employees')->assertOk()->assertSee('أحمد');
        Livewire::test(ListEmployees::class)
            ->callTableAction('handOver', $this->ahmed, data: ['amount' => 250000, 'money_account_id' => $this->cash()->id])
            ->assertHasNoTableActionErrors()
            ->callTableAction('giveAdvance', $this->ahmed, data: ['amount' => 50000, 'reason' => 'سلفة', 'money_account_id' => $this->cash()->id])
            ->assertHasNoTableActionErrors();
        $this->assertSame(0, $this->finance()->custodyBalance($this->ahmed));

        Livewire::test(ListEmployeeAdvances::class)
            ->callTableAction('repay', EmployeeAdvance::sole(), data: ['amount' => 20000, 'money_account_id' => $this->cash()->id])
            ->assertHasNoTableActionErrors();
        $this->assertSame(30000, EmployeeAdvance::sole()->balance);

        $this->get(EmployeeStatement::getUrl(['user' => $this->ahmed->id]))->assertOk()->assertSee('تسديد عهدة للصندوق')->assertSee('سلفة');
        $this->get(EmployeeReports::getUrl())->assertOk()->assertSee('أحمد');
    }

    public function test_an_employee_sees_only_their_own_statement(): void
    {
        $other = User::factory()->create(['name' => 'علي الموظف']);
        $this->actingAs($this->ahmed);

        Livewire::test(EmployeeStatement::class, ['user' => (string) $other->id])->assertSee('أحمد')->assertDontSee('علي الموظف');
        $this->get('/employees')->assertForbidden();
    }

    public function test_the_custody_box_is_created_once_per_employee(): void
    {
        $a = $this->finance()->custodyAccount($this->ahmed);
        $b = $this->finance()->custodyAccount($this->ahmed);

        $this->assertTrue($a->is($b));
        $this->assertSame(MoneyAccountKind::Custody, $a->kind);
    }
}
