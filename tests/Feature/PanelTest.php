<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Activations\Pages\ListActivations;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Subscribers\Pages\CreateSubscriber;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\ActivationService;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PanelTest extends TestCase
{
    public static function pages(): array
    {
        return array_map(fn ($p) => [$p], [
            '/', '/subscribers', '/subscribers/create', '/accounts', '/activations', '/follow-ups', '/debts', '/payments',
            '/debt-transfers', '/money-accounts', '/company-settlements', '/expenses', '/service-plans', '/promotions',
            '/users', '/audit-logs', '/daily-report', '/settings',
        ]);
    }

    #[DataProvider('pages')]
    public function test_every_page_opens_for_the_admin(string $path): void
    {
        $account = $this->account();
        app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);

        $this->get($path)->assertOk();
    }

    public function test_subscriber_page_and_receipt_open(): void
    {
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);
        $payment = app(\App\Services\PaymentService::class)->record($account, 35000, \App\Enums\PaymentMethod::Cash, $this->cash());

        $this->get("/subscribers/{$account->subscriber_id}")->assertOk()->assertSee($account->subscriber->full_name);
        $this->get(route('receipts.show', $payment))->assertOk()
            ->assertSee($payment->receipt_number)
            ->assertSee('خمسة وثلاثون ألف دينار عراقي');
    }

    public function test_employee_cannot_open_admin_pages(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->get('/subscribers')->assertOk();
        $this->get('/users')->assertForbidden();
        $this->get('/settings')->assertForbidden();
    }

    public function test_login_page_uses_username(): void
    {
        auth()->logout();

        $this->get('/login')->assertOk()->assertSee('اسم المستخدم');
    }

    public function test_create_subscriber_with_first_account_through_the_form(): void
    {
        Livewire::test(CreateSubscriber::class)
            ->fillForm([
                'full_name' => 'حسين علي',
                'phone' => '07815550192',
                'account' => ['username' => 'hussein.a44', 'secret' => 'x1y2', 'fat_code' => 'FAT-03', 'pole_number' => '22'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $subscriber = Subscriber::where('full_name', 'حسين علي')->sole();
        $this->assertSame('9647815550192', $subscriber->phone_normalized);
        $this->assertSame('hussein.a44', $subscriber->accounts()->sole()->username);
    }

    public function test_activate_pay_and_transfer_through_the_actions(): void
    {
        $account = $this->account();

        Livewire::test(ListActivations::class)
            ->callAction('newActivation', data: [
                'account_id' => $account->id,
                'plan_id' => $this->plan('plus')->id,
                'kind' => ActivationKind::Partial7->value,
                'starts_at' => now()->format('Y-m-d H:i'),
            ])
            ->assertHasNoActionErrors();

        $debt = $account->debts()->sole();
        $this->assertSame(45000, $debt->balance);
        $this->assertSame(DebtBucket::Secondary, $debt->bucket);

        Livewire::test(ListPayments::class)
            ->callAction('newPayment', data: [
                'account_id' => $account->id,
                'payment_type' => 'debt_payment',
                'method' => 'cash',
                'money_account_id' => $this->cash()->id,
                'amount' => 20000,
                'transfer_remainder' => true,
            ])
            ->assertHasNoActionErrors();

        $debt->refresh();
        $this->assertSame(DebtBucket::Primary, $debt->bucket);
        $this->assertSame(25000, $debt->balance);
        $this->assertSame(1, Payment::count());
    }

    public function test_transfer_and_follow_up_from_the_follow_up_list(): void
    {
        $account = $this->account();
        $activation = app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Partial7);

        Livewire::test(ListFollowUps::class)
            ->callTableAction('followUp', $activation, data: ['channel' => 'call', 'outcome' => 'promised_to_pay', 'note' => 'بعد الراتب'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('transfer', $activation, data: ['reason' => 'لم يسدد'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $account->followUps()->count());
        $this->assertSame(DebtBucket::Primary, $activation->debt->fresh()->bucket);
    }

    public function test_business_errors_show_as_notifications_not_crashes(): void
    {
        $account = $this->account();
        $debt = app(\App\Services\DebtService::class)->create($account, 10000);
        app(\App\Services\PaymentService::class)->record($account, 5000, \App\Enums\PaymentMethod::Cash, $this->cash());

        Livewire::test(ListDebts::class, ['activeTab' => 'primary'])
            ->callTableAction('voidDebt', $debt, data: ['reason' => 'خطأ'])
            ->assertNotified('على الدين تسديدات. ألغِ سندات القبض أولاً ثم ألغِ الدين.');

        $this->assertSame(DebtStatus::Partial, $debt->fresh()->status);
    }

    public function test_dashboard_renders_widgets(): void
    {
        Livewire::test(Dashboard::class)->assertOk();
    }
}
