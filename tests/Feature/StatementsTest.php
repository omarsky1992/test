<?php

namespace Tests\Feature;

use App\Enums\PaymentType;
use App\Filament\Pages\EmployeeStatement;
use App\Filament\Pages\MoneyAccountStatement;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethodType;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Services\PaymentService;
use App\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\TestCase;

class StatementsTest extends TestCase
{
    private function treasury(): TreasuryService
    {
        return app(TreasuryService::class);
    }

    public function test_the_box_statement_lists_every_movement_with_the_balance_after_it(): void
    {
        $this->treasury()->openingBalance($this->cash(), 100000);
        $this->travelTo(now()->addHour());
        app(PaymentService::class)->record($this->account('زينب علي'), 35000, moneyAccount: $this->cash(), type: PaymentType::Advance);
        $this->travelTo(now()->addHour());
        $this->treasury()->recordExpense(ExpenseCategory::where('name_ar', 'كابلات ومواد')->first(), 'كيبل', 20000, $this->cash());

        $statement = $this->treasury()->statement($this->cash());

        $this->assertSame([
            ['رصيد ابتدائي', 100000, 0, 100000],
            ['قبض من مشترك', 35000, 0, 135000],
            ['مصروف / مشتريات', 0, 20000, 115000],
        ], $statement['rows']->map(fn ($r) => [$r['label'], $r['in'], $r['out'], $r['balance']])->all());
        $this->assertStringContainsString('زينب علي', $statement['rows'][1]['details']);
        $this->assertSame($this->admin->name, $statement['rows'][2]['by']);
        $this->assertSame(115000, $statement['current']);
        $this->assertSame([135000, 20000, 115000], [$statement['total_in'], $statement['total_out'], $statement['closing']]);

        // A period starts from the balance before it.
        $later = $this->treasury()->statement($this->cash(), CarbonImmutable::today()->addDay());
        $this->assertSame(115000, $later['opening']);
        $this->assertCount(0, $later['rows']);

        Livewire::test(MoneyAccountStatement::class, ['box' => (string) $this->cash()->id, 'from' => ''])
            ->assertOk()->assertSee('الرصيد الناتج')->assertSee('زينب علي')->assertSee('115,000');
    }

    public function test_the_employee_statement_shows_the_running_balances(): void
    {
        $ahmed = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $ahmed->assignRole('employee');
        $finance = app(EmployeeFinance::class);
        $this->treasury()->openingBalance($this->cash(), 500000);

        $this->actingAs($ahmed);
        $cash = PaymentMethodType::where('code', 'cash')->first();
        app(PaymentService::class)->record($this->account(username: 'u1'), type: PaymentType::Advance, lines: [
            ['method' => $cash, 'money_account' => $finance->custodyAccount($ahmed), 'amount' => 300000],
        ]);
        $this->actingAs($this->admin);
        $this->travelTo(now()->addHour());
        $finance->handOver($ahmed, 100000, $this->cash());
        $this->travelTo(now()->addHour());
        $finance->giveAdvance($ahmed, 50000, 'سلفة', $this->cash());

        $rows = $finance->statement($ahmed);
        // Newest first; each row carries the custody and advance balances after it.
        $this->assertSame([[0, 50000, 200000, 50000], [-100000, 0, 200000, 0], [300000, 0, 300000, 0]],
            $rows->map(fn ($r) => [$r['custody'], $r['advance'], $r['custody_balance'], $r['advance_balance']])->all());

        Livewire::test(EmployeeStatement::class, ['user' => (string) $ahmed->id])->assertOk()->assertSee('رصيد العهدة الناتج')->assertSee('200,000');
    }

    public function test_the_box_statement_is_for_the_money_managers(): void
    {
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->get(MoneyAccountStatement::getUrl())->assertForbidden();
    }
}
