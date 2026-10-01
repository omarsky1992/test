<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\Settings;
use App\Imports\SpreadsheetReader;
use App\Imports\SubscriberImporter;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\EmployeeAdvance;
use App\Models\FinancialTransaction;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\PaymentMethodType;
use App\Models\ServicePlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ActivationService;
use App\Services\EmployeeFinance;
use App\Services\PaymentService;
use App\Services\SystemReset;
use App\Services\TreasuryService;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SystemResetTest extends TestCase
{
    private function trialData(): User
    {
        $this->admin->update(['password' => 'admin-pass-1']);
        $employee = User::factory()->create(['name' => 'موظف تجريبي']);
        $employee->assignRole('employee');

        app(TreasuryService::class)->openingBalance($this->cash(), 1000000);
        $account = $this->account();
        app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);
        app(PaymentService::class)->record($account, 10000, \App\Enums\PaymentMethod::Cash, $this->cash());
        $finance = app(EmployeeFinance::class);
        $finance->custodyAccount($employee);
        $advance = $finance->giveAdvance($employee, 50000, 'تجربة', $this->cash());
        $finance->repay($advance, 10000, $this->cash());

        return $employee;
    }

    public function test_reset_removes_trial_operations_and_keeps_the_setup(): void
    {
        $employee = $this->trialData();
        $boxes = MoneyAccount::count();

        $counts = app(SystemReset::class)->run($this->admin->fresh(), 'admin-pass-1', withSubscribers: true);

        $this->assertGreaterThan(0, $counts['financial_transactions']);
        foreach ([FinancialTransaction::class, Payment::class, Debt::class, EmployeeAdvance::class, Subscriber::class, Account::class] as $model) {
            $this->assertSame(0, $model::count(), $model);
        }
        // Kept: users, plans, payment methods, boxes (now at zero), settings and the audit log.
        $this->assertTrue(User::whereKey($this->admin->id)->exists());
        $this->assertTrue(User::whereKey($employee->id)->exists());
        $this->assertSame(4, ServicePlan::count());
        $this->assertTrue(PaymentMethodType::where('code', 'super_key')->exists());
        $this->assertSame($boxes, MoneyAccount::count());
        $this->assertSame(0, app(TreasuryService::class)->balance($this->cash()));
        $this->assertTrue(AuditLog::where('action', 'system.reset')->exists());
        $this->assertTrue(AuditLog::where('action', 'payment.created')->orWhere('action', 'advance.created')->exists());
    }

    public function test_reset_needs_the_admin_password(): void
    {
        $this->trialData();

        try {
            app(SystemReset::class)->run($this->admin->fresh(), 'wrong', true);
            $this->fail('A wrong password must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('كلمة المرور غير صحيحة.', $e->getMessage());
        }
        $this->assertGreaterThan(0, Payment::count());
    }

    public function test_only_an_admin_can_reset(): void
    {
        $employee = User::factory()->create(['password' => 'emp-pass-12']);
        $employee->assignRole('employee');

        $this->expectException(BusinessRuleException::class);
        app(SystemReset::class)->run($employee, 'emp-pass-12', true);
    }

    public function test_reset_from_the_settings_page_with_confirmation_then_reimport_from_excel(): void
    {
        $this->trialData();

        Livewire::test(Settings::class)
            ->callAction('resetSystem', data: ['with_subscribers' => true, 'password' => 'admin-pass-1', 'confirm' => 'خطأ'])
            ->assertHasActionErrors(['confirm']);
        $this->assertGreaterThan(0, Subscriber::count());

        Livewire::test(Settings::class)
            ->callAction('resetSystem', data: ['with_subscribers' => true, 'password' => 'admin-pass-1', 'confirm' => 'تصفير'])
            ->assertHasNoActionErrors()
            ->assertNotified('تم تصفير النظام');
        $this->assertSame(0, Subscriber::count());

        $path = tempnam(sys_get_temp_dir(), 'again').'.csv';
        file_put_contents($path, "معرف المشترك,الاسم,رقم الهاتف,اسم الجهاز\n2825350,أحمد كريم,7701234567,FBG876F33P08\n");
        $importer = app(SubscriberImporter::class);
        $run = $importer->execute($path, 'again.csv', $importer->suggestMapping(app(SpreadsheetReader::class)->read($path, 'again.csv')['headers']));
        @unlink($path);

        $this->assertSame('success', $run->status);
        $this->assertSame('C-000001', Subscriber::sole()->code);
        $this->assertSame(1, Account::count());
    }

    public function test_the_reset_button_is_hidden_from_employees(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('employee');
        $manager->givePermissionTo('settings.manage');
        $this->actingAs($manager);

        Livewire::test(Settings::class)->assertActionHidden('resetSystem');
    }
}
