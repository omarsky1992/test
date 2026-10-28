<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\MoneyAccountKind;
use App\Filament\Pages\RotationReportPage;
use App\Models\DeviceType;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Services\DebtService;
use App\Services\EmployeeFinance;
use App\Services\RotationReport;
use App\Services\TreasuryService;
use Livewire\Livewire;
use Tests\TestCase;

class RotationReportTest extends TestCase
{
    private function setUpMoney(): void
    {
        $treasury = app(TreasuryService::class);
        // Primary debts 50,000 (the secondary one is not counted).
        app(DebtService::class)->create($this->account('علي'), 50000, DebtBucket::Primary);
        app(DebtService::class)->create($this->account('حسن'), 9000, DebtBucket::Secondary);
        // Cards 30,000.
        $card = $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'كي كارد');
        $treasury->openingBalance($card, 30000);
        // Custody 20,000.
        $ahmed = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $ahmed->assignRole('employee');
        $treasury->openingBalance(app(EmployeeFinance::class)->custodyAccount($ahmed), 20000);
        // Profit: 7,000 sales − 5,000 purchases = 2,000.
        $treasury->openingBalance($this->cash(), 100000);
        $treasury->recordExpense(ExpenseCategory::first(), 'مواد', 5000, $this->cash());
        $treasury->recordDeviceSale(DeviceType::where('key', 'card')->firstOrFail(), 'كارت', 7000, 1, null, $this->cash());
    }

    public function test_it_adds_debts_cards_custody_profit_and_the_typed_cash_and_page_balance(): void
    {
        $this->setUpMoney();
        $report = app(RotationReport::class)->compute();
        $this->assertSame([50000, 30000, 20000, 2000], array_column($report['lines'], 'amount'));
        $this->assertSame(102000, $report['total']);

        Livewire::test(RotationReportPage::class)
            ->assertSee('102,000')
            ->set('cash', '٢٥٠٠٠٠')->set('pageBalance', '79,471')
            ->assertSee('الكاش')->assertSee('رصيد الصفحة')
            ->assertSee('431,471');
    }

    public function test_it_is_sent_to_the_chosen_numbers_and_needs_its_permission(): void
    {
        $this->setUpMoney();
        $ali = WhatsappNumber::create(['phone' => '9647701111111', 'label' => 'علي', 'user_id' => $this->admin->id, 'is_active' => true]);
        WhatsappNumber::create(['phone' => '9647702222222', 'label' => 'عمر', 'user_id' => $this->admin->id, 'is_active' => true]);

        Livewire::test(RotationReportPage::class)->set('cash', '1000')
            ->callAction('sendWhatsapp', data: ['numbers' => [(string) $ali->id]])
            ->assertNotified('أُرسل التقرير إلى 1 رقم');
        $sent = WhatsappOutbox::sole();
        $this->assertSame([$ali->phone, 'alert_report'], [$sent->to_phone, $sent->kind]);
        $this->assertStringContainsString('📊 تقرير الدوّار', $sent->body);
        $this->assertStringContainsString('الكاش: 1,000', $sent->body);
        $this->assertStringContainsString('المجموع: 103,000', $sent->body);

        // None chosen: every authorized number.
        Livewire::test(RotationReportPage::class)->callAction('sendWhatsapp', data: ['numbers' => []]);
        $this->assertSame(3, WhatsappOutbox::count());

        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);
        $this->get(RotationReportPage::getUrl())->assertForbidden();
    }
}
