<?php

namespace Tests\Feature;

use App\Enums\MoneyAccountKind;
use App\Enums\PaymentType;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\PaymentMethodType;
use App\Models\User;
use App\Services\Audit;
use App\Services\EmployeeFinance;
use App\Services\PaymentService;
use App\Services\TreasuryService;
use App\Support\AuditDetails;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogDetailsTest extends TestCase
{
    public function test_a_payment_shows_to_whom_from_where_and_how(): void
    {
        $ahmed = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $ahmed->assignRole('employee');
        $custody = app(EmployeeFinance::class)->custodyAccount($ahmed);
        $cash = PaymentMethodType::where('code', 'cash')->sole();
        $account = $this->account('علي حسين');

        $this->actingAs($ahmed);
        app(PaymentService::class)->record($account, type: PaymentType::Advance, lines: [['method' => $cash, 'money_account' => $custody, 'amount' => 35000]]);
        $this->actingAs($this->admin);

        $log = AuditLog::where('action', 'payment.created')->sole();
        $this->assertSame('علي حسين — '.$account->username, AuditDetails::target($log));
        $this->assertStringContainsString('لوحة التحكم', AuditDetails::origin($log));
        $this->assertStringContainsString('الصندوق: '.$custody->name, AuditDetails::origin($log));
        $summary = AuditDetails::summary($log);
        $this->assertStringContainsString('المبلغ: 35,000', $summary);
        $this->assertStringContainsString('النوع: دفع مقدم', $summary);
        $this->assertStringContainsString('طريقة الدفع: '.$cash->name_ar.' 35,000', $summary);

        Livewire::test(ListAuditLogs::class)
            ->assertSee('علي حسين')->assertSee($custody->name)->assertSee('دفع مقدم')->assertSee('أحمد')
            ->filterTable('source', 'whatsapp')->assertDontSee('علي حسين');
    }

    public function test_a_change_shows_the_old_and_the_new_value(): void
    {
        $box = app(TreasuryService::class)->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'سوبر كي');
        $log = app(Audit::class)->log('payment.box_changed', 'Payment', ['money_account' => 'عهدة أحمد'], ['money_account' => $box->name, 'amount' => 5000]);

        $this->assertSame('الصندوق: عهدة أحمد ← سوبر كي · المبلغ: 5,000 د.ع', AuditDetails::summary($log));
        $this->assertSame(['الصندوق' => 'عهدة أحمد'], AuditDetails::details($log->old_values));
    }
}
