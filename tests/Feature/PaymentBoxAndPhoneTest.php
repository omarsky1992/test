<?php

namespace Tests\Feature;

use App\Enums\MoneyAccountKind;
use App\Enums\PaymentType;
use App\Filament\Pages\WhatsappReminders;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Subscribers\Pages\ViewSubscriber;
use App\Models\PaymentMethodType;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Services\PaymentService;
use App\Services\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentBoxAndPhoneTest extends TestCase
{
    public function test_a_receipt_put_in_a_custody_by_mistake_is_moved_to_the_right_card(): void
    {
        $treasury = app(TreasuryService::class);
        $card = $treasury->createMoneyAccount($this->admin->branch_id, MoneyAccountKind::Electronic, 'سوبر كي');
        $ahmed = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $ahmed->assignRole('employee');
        $custody = app(EmployeeFinance::class)->custodyAccount($ahmed);
        $cash = PaymentMethodType::where('code', 'cash')->sole();
        $qi = PaymentMethodType::where('code', 'qi_card')->sole();

        $this->actingAs($ahmed);
        $payment = app(PaymentService::class)->record($this->account(), type: PaymentType::Advance, lines: [['method' => $cash, 'money_account' => $custody, 'amount' => 35000]]);
        $this->actingAs($this->admin);
        $this->assertSame(35000, $treasury->balance($custody));

        Livewire::test(ListPayments::class)
            ->callTableAction('changePaymentBox', $payment, ['money_account_id' => $card->id, 'method_id' => $qi->id, 'reason' => 'الدفع كان على سوبر كي'])
            ->assertHasNoTableActionErrors()->assertNotified();

        $line = $payment->lines()->sole();
        $this->assertSame([$card->id, $qi->id], [$line->money_account_id, $line->payment_method_id]);
        $this->assertSame([0, 35000], [$treasury->balance($custody), $treasury->balance($card)]);
        $this->assertSame($card->id, $payment->fresh()->money_account_id);
        $change = DB::table('payment_box_changes')->sole();
        $this->assertSame([$custody->id, $card->id, 35000], [$change->from_money_account_id, $change->to_money_account_id, (int) $change->amount]);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'payment.box_changed')->exists());
        // The employee's statement shows the correction; his collections no longer count it.
        $this->assertContains('box_change', app(EmployeeFinance::class)->statement($ahmed)->pluck('kind')->all());
        $this->assertSame(0, app(EmployeeFinance::class)->summary($ahmed)['collections']);

        // A later void takes the money back from the card, never from the custody.
        app(PaymentService::class)->void($payment->fresh(), 'تجربة');
        $this->assertSame([0, 0], [$treasury->balance($custody), $treasury->balance($card)]);
    }

    public function test_only_who_may_correct_receipts_can(): void
    {
        $payment = app(PaymentService::class)->record($this->account(), 10000, moneyAccount: $this->cash(), type: PaymentType::Advance);
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->expectException(AuthorizationException::class);
        app(PaymentService::class)->changeBox($payment, $payment->lines()->sole(), $this->companyBox(), null, 'x');
    }

    public function test_the_messages_can_go_to_the_second_number(): void
    {
        $account = $this->account('علي حسين', phone: '07701110001');
        $subscriber = $account->subscriber;
        $subscriber->update(['alt_phone' => '07809990002']);
        $this->assertSame('9647701110001', WhatsappReminders::phone($account->fresh()));

        Livewire::test(ViewSubscriber::class, ['record' => $subscriber->id])
            ->assertSee('07701110001')->assertSee('07809990002')
            ->callAction('notifyPhone')->assertNotified();

        $this->assertTrue($subscriber->fresh()->notify_alt_phone);
        $this->assertSame('9647809990002', WhatsappReminders::phone($account->fresh()));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'subscriber.notify_phone')->exists());
    }
}
