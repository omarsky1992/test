<?php

namespace Tests\Feature;

use App\Enums\DebtStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Filament\Pages\EmployeeHome;
use App\Filament\Pages\Settings as SettingsPage;
use App\Filament\Pages\WhatsappReminders;
use App\Filament\Resources\HandoverRequests\Pages\ListHandoverRequests;
use App\Filament\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Filament\Resources\SubscriberCards\Pages\ListSubscriberCards;
use App\Models\Account;
use App\Models\ActivationDue;
use App\Models\AuditLog;
use App\Models\CustodyHandoverRequest;
use App\Models\Debt;
use App\Models\MessageTemplate;
use App\Models\PaymentMethodType;
use App\Models\User;
use App\Services\DebtService;
use App\Services\EmployeeFinance;
use App\Services\PaymentService;
use App\Services\RenewalService;
use App\Services\Settings;
use App\Services\TreasuryService;
use App\Support\SubscriberStatus;
use Carbon\CarbonImmutable;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Livewire\Livewire;
use Tests\Support\FakeCompanyClient;
use Tests\TestCase;

class EmployeeUiTest extends TestCase
{
    /** A subscriber on a 7-day renewal with its secondary debt of 35,000. */
    private function renewed(string $name = 'زينب كريم', int $daysLeft = 7): Account
    {
        $account = $this->account($name, phone: '0790'.random_int(1000000, 9999999));
        $ends = now()->addDays($daysLeft)->addHour();
        $account->update(['current_plan_id' => $this->plan()->id, 'external_ends_at' => $ends, 'company_days_left' => $daysLeft]);
        app(RenewalService::class)->record($account, 0, 7, null, CarbonImmutable::parse($ends));

        return $account->fresh();
    }

    private function pay(Account $account, int $amount): \App\Models\Payment
    {
        return app(PaymentService::class)->record($account, $amount, PaymentMethod::Cash, $this->cash());
    }

    private function employee(): User
    {
        $user = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');

        return $user;
    }

    // ---- «يجب التفعيل» ----

    public function test_paying_a_secondary_debt_in_full_puts_the_subscriber_in_must_activate(): void
    {
        $account = $this->renewed();

        $this->pay($account, 20000);
        $this->assertSame(0, ActivationDue::count(), 'a partial payment does not count');

        $this->pay($account, 15000);
        $due = ActivationDue::sole();
        $this->assertSame(['pending', $account->id, 35000], [$due->status, $due->account_id, $due->amount]);
        $this->assertTrue(AuditLog::where('action', 'activation_due.opened')->exists());
        $this->assertSame(1, SubscriberStatus::counts()['must_activate']);
    }

    public function test_a_primary_debt_or_an_already_activated_account_does_not_count(): void
    {
        $primary = $this->account('علي حسين');
        app(DebtService::class)->create($primary, 20000);
        $this->pay($primary, 20000);

        $long = $this->renewed('حسن جبار', daysLeft: 25);
        $this->pay($long, 35000);

        $this->assertSame(0, ActivationDue::count());
    }

    public function test_voiding_the_payment_takes_the_subscriber_off_the_list(): void
    {
        $account = $this->renewed();
        $payment = $this->pay($account, 35000);

        app(PaymentService::class)->void($payment, 'خطأ');

        $this->assertSame('cancelled', ActivationDue::sole()->status);
        $this->assertSame(0, SubscriberStatus::counts()['must_activate']);
    }

    public function test_the_company_sync_closes_it_when_the_end_date_moves_forward(): void
    {
        $site = new FakeCompanyClient;
        $this->app->instance(\App\Sync\CompanyClient::class, $site);
        $record = ['customer_id' => '77', 'name' => 'زينب كريم', 'plan' => 'BASIC', 'status' => 'Active', 'username' => 'ZK1', 'subscription_id' => '501',
            'ends_at' => now()->addDays(7)->toIso8601String()];
        $site->set($record);
        app(\App\Sync\CompanySync::class)->run('manual');
        $account = Account::sole();
        app(RenewalService::class)->record($account, 0, 7, null, $account->external_ends_at);
        $this->pay($account->fresh(), 35000);

        // A few minutes of difference on the site is not an activation.
        $site->set(['ends_at' => now()->addDays(7)->addMinutes(30)->toIso8601String()] + $record);
        app(\App\Sync\CompanySync::class)->run('manual');
        $this->assertSame('pending', ActivationDue::sole()->status);

        // The full activation adds the remaining days.
        $site->set(['ends_at' => now()->addDays(30)->toIso8601String()] + $record);
        app(\App\Sync\CompanySync::class)->run('manual');
        $due = ActivationDue::sole();
        $this->assertSame(['done', 'sync'], [$due->status, $due->resolved_via]);
    }

    public function test_the_employee_confirms_it_by_hand(): void
    {
        $account = $this->renewed();
        $this->pay($account, 35000);
        $employee = $this->employee();
        $this->actingAs($employee);

        Livewire::test(EmployeeHome::class)->assertSee('زينب كريم')->call('markDone', $account->id);

        $due = ActivationDue::sole();
        $this->assertSame(['done', 'manual', $employee->id], [$due->status, $due->resolved_via, $due->resolved_by]);

        $stranger = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $this->actingAs($stranger);
        Livewire::test(EmployeeHome::class)->call('markDone', $account->id)->assertForbidden();
    }

    public function test_the_secondary_debt_can_be_moved_to_primary_from_the_employee_interface(): void
    {
        $account = $this->renewed();
        $employee = $this->employee();
        $this->actingAs($employee);

        Livewire::test(ListSubscriberCards::class)->assertTableActionVisible('transfer', $account)
            ->callTableAction('transfer', $account, ['reason' => 'لم يسدد'])->assertHasNoTableActionErrors();

        $debt = Debt::sole();
        $this->assertSame(\App\Enums\DebtBucket::Primary, $debt->bucket);
        $this->assertSame(1, \App\Models\DebtTransfer::count());
        $this->assertSame($employee->id, \App\Models\DebtTransfer::sole()->performed_by);

        $other = $this->account('علي حسين');
        Livewire::test(ListSubscriberCards::class)->assertTableActionHidden('transfer', $other);

        // The home's secondary-debts tile opens the debts list, which has مناقلة on each debt.
        $this->get(EmployeeHome::getUrl())->assertOk()->assertSee('debts?tab=secondary', escape: false)->assertSee('متابعة الـ7 أيام');
    }

    public function test_a_sync_never_deletes_or_moves_a_secondary_debt(): void
    {
        $site = new FakeCompanyClient;
        $this->app->instance(\App\Sync\CompanyClient::class, $site);
        $record = ['customer_id' => '88', 'name' => 'زينب كريم', 'plan' => 'BASIC', 'username' => 'ZK2', 'subscription_id' => '601'];
        $site->set(['status' => 'Expired', 'ends_at' => now()->subDays(3)->toIso8601String()] + $record);
        app(\App\Sync\CompanySync::class)->run('manual');

        $this->travelTo(now()->addHour());
        $site->set(['status' => 'Active', 'ends_at' => now()->addDays(7)->toIso8601String()] + $record);
        app(\App\Sync\CompanySync::class)->run('manual');
        $debt = Debt::sole();
        $this->assertSame([\App\Enums\DebtBucket::Secondary, 35000], [$debt->bucket, $debt->balance]);

        // Activated in full on the site while still owing: the debt stays exactly as it is.
        $this->travelTo(now()->addDays(2));
        $site->set(['status' => 'Active', 'ends_at' => now()->addDays(30)->toIso8601String()] + $record);
        app(\App\Sync\CompanySync::class)->run('manual');

        $debt->refresh();
        $this->assertSame([\App\Enums\DebtBucket::Secondary, DebtStatus::Open, 35000], [$debt->bucket, $debt->status, $debt->balance]);
        $this->assertSame(0, \App\Models\DebtTransfer::count());
        $this->assertSame(35000, $this->balanceOf(\App\Services\Ledger::AR_SECONDARY));
        $this->assertSame(1, Debt::count());
    }

    // ---- Custody handover requests ----

    public function test_a_handover_request_moves_the_money_only_when_the_admin_confirms(): void
    {
        $ahmed = $this->employee();
        $finance = app(EmployeeFinance::class);
        $this->actingAs($ahmed);
        app(PaymentService::class)->record($this->account(username: 'u1'), type: PaymentType::Advance, lines: [
            ['method' => PaymentMethodType::where('code', 'cash')->first(), 'money_account' => $finance->custodyAccount($ahmed), 'amount' => 300000],
        ]);

        try {
            $finance->requestHandover($ahmed, 400000);
            $this->fail('more than the custody accepted');
        } catch (\App\Exceptions\BusinessRuleException) {
        }
        $request = $finance->requestHandover($ahmed, 300000, 'سلمتها باليد');
        $this->expectExceptionOnSecond(fn () => $finance->requestHandover($ahmed, 1000));
        $this->assertSame(300000, $finance->custodyBalance($ahmed), 'nothing moves before the admin confirms');

        $this->actingAs($this->admin);
        Livewire::test(ListHandoverRequests::class)->assertSee('أحمد')
            ->callTableAction('approve', $request, ['amount' => 300000, 'money_account_id' => $this->cash()->id])->assertHasNoTableActionErrors();

        $request->refresh();
        $this->assertSame(['approved', $this->admin->id], [$request->status, $request->resolved_by]);
        $this->assertSame(0, $finance->custodyBalance($ahmed));
        $this->assertSame(300000, app(TreasuryService::class)->balance($this->cash()));
        $this->assertTrue(AuditLog::where('action', 'custody.handover_approved')->exists());
    }

    public function test_a_rejected_handover_leaves_the_custody(): void
    {
        $ahmed = $this->employee();
        $finance = app(EmployeeFinance::class);
        $this->actingAs($ahmed);
        app(PaymentService::class)->record($this->account(username: 'u2'), type: PaymentType::Advance, lines: [
            ['method' => PaymentMethodType::where('code', 'cash')->first(), 'money_account' => $finance->custodyAccount($ahmed), 'amount' => 50000],
        ]);
        $request = $finance->requestHandover($ahmed, 50000);
        $this->actingAs($this->admin);

        $finance->rejectHandover($request, 'لم أستلم');

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame(50000, $finance->custodyBalance($ahmed));
    }

    public function test_the_employee_requests_from_their_statement(): void
    {
        $ahmed = $this->employee();
        $finance = app(EmployeeFinance::class);
        $this->actingAs($ahmed);
        app(PaymentService::class)->record($this->account(username: 'u3'), type: PaymentType::Advance, lines: [
            ['method' => PaymentMethodType::where('code', 'cash')->first(), 'money_account' => $finance->custodyAccount($ahmed), 'amount' => 80000],
        ]);

        Livewire::test(\App\Filament\Pages\EmployeeStatement::class)
            ->callAction('requestHandover', ['amount' => 80000, 'notes' => 'اليوم'])
            ->assertHasNoActionErrors();

        $this->assertSame(['pending', 80000], [CustodyHandoverRequest::sole()->status, CustodyHandoverRequest::sole()->amount]);
        $this->get(ListHandoverRequests::getUrl())->assertForbidden();
    }

    // ---- Subscriber groups ----

    public function test_subscriber_groups_follow_the_end_dates_debts_and_the_setting(): void
    {
        $this->account('فعال')->update(['external_ends_at' => now()->addDays(20)]);
        $soon = $this->account('قريب');
        $soon->update(['external_ends_at' => now()->addDays(5)]);
        $this->account('بعد عشرة')->update(['external_ends_at' => now()->addDays(10)->addHour()]);
        $gone = $this->account('منتهي');
        $gone->update(['external_ends_at' => now()->subDay()]);
        app(DebtService::class)->create($gone, 10000);
        app(DebtService::class)->create($soon, 5000);

        $counts = SubscriberStatus::counts();
        $this->assertSame([3, 1, 1, 1, 1], [$counts['active'], $counts['expiring'], $counts['expired'], $counts['active_primary'], $counts['expired_primary']]);

        app(Settings::class)->set('subscribers.expiring_days', 10);
        $this->assertSame(2, SubscriberStatus::counts()['expiring']);
    }

    public function test_the_cards_list_shows_tabs_search_and_card_actions(): void
    {
        $account = $this->renewed();
        $this->pay($account, 35000);
        $this->account('علي حسين');

        Livewire::test(ListSubscriberCards::class)->assertOk()->assertSee('يجب التفعيل')->assertSee('زينب كريم')->assertSee('علي حسين')
            ->set('activeTab', 'must_activate')->assertSee('زينب كريم')->assertDontSee('علي حسين')
            ->callTableAction('markDone', $account);

        $this->assertSame('done', ActivationDue::sole()->status);

        Livewire::test(ListSubscriberCards::class)->searchTable('علي')->assertSee('علي حسين')->assertDontSee('زينب كريم');
    }

    // ---- Interface: home, switch, colour ----

    public function test_employees_land_on_their_home_and_the_admin_can_switch(): void
    {
        $this->get('/')->assertOk()->assertSee('الديون الثانوية');

        $employee = $this->employee();
        $this->flushSession();
        $this->actingAs($employee);
        Livewire::test(\App\Filament\Pages\Dashboard::class)->assertRedirect(EmployeeHome::getUrl());
        $this->get(EmployeeHome::getUrl())->assertOk()->assertSee('عهدتي الآن')->assertSee('يجب التفعيل');
        $this->post(route('ui.mode'))->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->admin);
        $this->post(route('ui.mode'))->assertRedirect(EmployeeHome::getUrl());
        $this->assertTrue($this->admin->fresh()->usesEmployeeUi());
        $this->post(route('ui.mode'))->assertRedirect(\App\Filament\Pages\Dashboard::getUrl());
        $this->assertFalse($this->admin->fresh()->usesEmployeeUi());
    }

    public function test_each_user_picks_their_own_colour(): void
    {
        $this->post(route('ui.theme'), ['color' => 'violet'])->assertRedirect();
        $this->post(route('ui.theme'), ['color' => 'gold'])->assertSessionHasErrors('color');
        $this->assertSame('violet', $this->admin->fresh()->theme_color);

        $this->actingAs($this->admin->fresh())->get(EmployeeHome::getUrl())->assertOk();
        $this->assertSame(Color::Violet, FilamentColor::getColors()['primary']);

        $other = $this->employee();
        $this->assertNull($other->fresh()->theme_color);
    }

    // ---- WhatsApp reminders and templates ----

    public function test_reminders_fill_the_chosen_message_and_open_whatsapp(): void
    {
        $account = $this->renewed('زينب كريم', daysLeft: 3);
        $template = MessageTemplate::where('title', 'قرب نهاية الاشتراك')->sole();
        $this->assertSame(4, MessageTemplate::count(), 'starter messages are seeded');

        $page = Livewire::test(WhatsappReminders::class, ['preselect' => $account->id])
            ->set('template', $template->id)
            ->assertSee('زينب كريم')
            ->assertSee('ينتهي بعد 3 يوم');
        $phone = WhatsappReminders::phone($account);
        $page->assertSee('https://wa.me/'.$phone, escape: false);
        $page->call('opened', $account->id);
        $this->assertTrue(AuditLog::where('action', 'whatsapp.reminder')->exists());

        $this->assertStringContainsString('35,000', WhatsappReminders::values($account->loadSum(['debts as open_due'], 'balance'))['{المبلغ}']);
    }

    public function test_only_the_admin_edits_the_messages_and_reminders_need_the_permission(): void
    {
        Livewire::test(ManageMessageTemplates::class)->assertOk()->assertSee('قرب نهاية الاشتراك')
            ->callAction('create', ['title' => 'عرض الشهر', 'body' => 'عرض خاص لك {الاسم}', 'sort_order' => 4, 'is_active' => true]);
        $this->assertTrue(MessageTemplate::where('title', 'عرض الشهر')->exists());

        $employee = $this->employee();
        $this->actingAs($employee);
        $this->get(ManageMessageTemplates::getUrl())->assertForbidden();
        $this->get(WhatsappReminders::getUrl())->assertOk();

        $this->actingAs(User::factory()->create(['branch_id' => $this->admin->branch_id]));
        $this->get(WhatsappReminders::getUrl())->assertForbidden();
    }

    public function test_the_expiring_days_are_set_in_the_settings(): void
    {
        Livewire::test(SettingsPage::class)->fillForm(['expiring_days' => 5])->call('save');

        $this->assertSame(5, app(Settings::class)->expiringDays());
    }

    private function expectExceptionOnSecond(callable $callback): void
    {
        try {
            $callback();
            $this->fail('a second pending request was accepted');
        } catch (\App\Exceptions\BusinessRuleException) {
        }
    }
}
