<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\Debt;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\DebtService;
use App\Services\PaymentService;
use App\Services\RenewalService;
use App\Support\SubscriberTimeline;
use App\Sync\CompanyClient;
use App\Sync\CompanySync;
use Tests\Support\FakeCompanyClient;
use Tests\TestCase;

/** «شنو صار ويا هذا المشترك»: why he has (or has no) debt, in sentences, and «رجوع» on every page. */
class SubscriberTimelineTest extends TestCase
{
    /** A subscriber the sync knows, then a short activation on the site (waits for «نعم/لا»). */
    private function synced(string $username, int $days): AccountRenewal
    {
        $site = new FakeCompanyClient;
        $this->app->instance(CompanyClient::class, $site);
        $record = ['customer_id' => 'c'.$username, 'name' => 'مشترك '.$username, 'plan' => 'BASIC', 'username' => $username, 'subscription_id' => 's'.$username];
        $site->set(['status' => 'Expired', 'ends_at' => now()->subDays(3)->toIso8601String()] + $record);
        app(CompanySync::class)->run('manual');
        $site->set(['status' => 'Active', 'ends_at' => now()->addDays($days)->toIso8601String()] + $record);
        app(CompanySync::class)->run('manual');

        return AccountRenewal::whereHas('account', fn ($q) => $q->where('username', $username))->sole();
    }

    private function texts(int $subscriberId): string
    {
        $story = SubscriberTimeline::for(Subscriber::findOrFail($subscriberId));

        return collect($story['now'])->pluck('text')->merge($story['events']->pluck('text'))->implode("\n");
    }

    public function test_it_tells_why_a_short_activation_has_no_debt(): void
    {
        $waiting = $this->synced('WAIT1', 7);
        $this->assertStringContainsString('ينتظر قرارك بـ«المكتشف 7 أيام»', $this->texts($waiting->subscriber_id));

        $declined = $this->synced('NO1', 7);
        app(RenewalService::class)->declineSecondary($declined);
        $text = $this->texts($declined->subscriber_id);
        $this->assertStringContainsString($this->admin->name.' ضغط «لا» بالمكتشف 7 أيام: ما انسجل دين ثانوي.', $text);
        $this->assertStringContainsString('ما عليه أي دين مفتوح هسه.', $text);

        $test = $this->synced('TEST1', 2);
        $this->assertStringContainsString('النظام اعتبره تيست من المشترك وما يسجّل دين', $this->texts($test->subscriber_id));

        $yes = $this->synced('YES1', 7);
        app(RenewalService::class)->approveSecondary($yes, 30000);
        $text = $this->texts($yes->subscriber_id);
        $this->assertStringContainsString('ضغط «نعم» بالمكتشف 7 أيام: انسجل دين ثانوي '.$yes->fresh()->debt->number.' بـ30,000', $text);
        $this->assertStringContainsString('عليه هسه: دين ثانوي 30,000', $text);
    }

    public function test_it_tells_payments_voided_debts_and_an_account_the_sync_does_not_read(): void
    {
        $account = $this->account('صادق تجربة');
        $debt = app(DebtService::class)->create($account, 20000, DebtBucket::Primary, notes: 'تنصيب');
        $payment = app(PaymentService::class)->record($account, 20000, PaymentMethod::Cash, $this->cash(), PaymentType::DebtPayment);
        $other = app(DebtService::class)->create($account, 15000, DebtBucket::Primary);
        app(DebtService::class)->void($other, 'سجلته بالغلط');

        $text = $this->texts($account->subscriber_id);
        $this->assertStringContainsString("انسجل دين أولي {$debt->number} بـ20,000", $text);
        $this->assertStringContainsString("سند قبض {$payment->receipt_number} بـ20,000 د.ع استلمه {$this->admin->name}", $text);
        $this->assertStringContainsString("سدّد: {$debt->number} (20,000 د.ع)", $text);
        $this->assertStringContainsString("انلغى الدين أولي {$other->number} (15,000 د.ع) من {$this->admin->name}، السبب: «سجلته بالغلط»", $text);
        $this->assertStringContainsString("الجهاز {$account->username} ما مربوط بموقع الشركة بعد", $text);

        // On the subscriber's page, for who may see it; money lines only with the debts/receipts permissions.
        $this->get(SubscriberResource::getUrl('view', ['record' => $account->subscriber_id]))->assertOk()
            ->assertSee('📜 شنو صار ويا هذا المشترك')->assertSee('السبب: «سجلته بالغلط»', false);
        $viewer = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $viewer->givePermissionTo('subscribers.view');
        $this->actingAs($viewer);
        $text = $this->texts($account->subscriber_id);
        $this->assertStringNotContainsString('سند قبض', $text);
        $this->assertStringNotContainsString('20,000', $text);
        $this->assertSame(2, Debt::count());
        $this->assertSame(1, Account::count());
    }

    public function test_every_page_but_the_home_pages_has_a_back_button(): void
    {
        $account = $this->account();
        $this->get(SubscriberResource::getUrl('view', ['record' => $account->subscriber_id]))->assertOk()->assertSee('class="subs-back"', false);
        $this->get('/debts?tab=secondary')->assertOk()->assertSee('class="subs-back"', false);
        $this->get('/')->assertOk()->assertDontSee('class="subs-back"', false);
        $this->get('/home')->assertOk()->assertDontSee('class="subs-back"', false);
    }
}
