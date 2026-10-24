<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscribers\Pages\ViewSubscriber;
use App\Models\ActivationReminder;
use App\Models\User;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Services\ActivationReminderService;
use App\WhatsApp\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\TestCase;

class ActivationReminderTest extends TestCase
{
    private function numbers(): array
    {
        return [
            WhatsappNumber::create(['phone' => '9647701111111', 'label' => 'علي', 'user_id' => $this->admin->id, 'is_active' => true]),
            WhatsappNumber::create(['phone' => '9647702222222', 'label' => 'عمر', 'user_id' => $this->admin->id, 'is_active' => true]),
            WhatsappNumber::create(['phone' => '9647703333333', 'label' => 'حسين', 'user_id' => $this->admin->id, 'is_active' => true]),
        ];
    }

    public function test_the_chosen_numbers_get_activate_7_days_right_after_the_subscription_ends(): void
    {
        [$ali, $omar] = $this->numbers();
        $account = $this->account('مرتضى سليم', 'FBG876F31P12FO');
        $account->update(['external_ends_at' => now()->addDays(3)]);
        $account->subscriber->update(['notes' => '41']);

        Livewire::test(ViewSubscriber::class, ['record' => $account->subscriber_id])
            ->callAction('activationReminder', data: ['numbers' => [(string) $ali->id, (string) $omar->id]])
            ->assertNotified('⏰ فُعّل التذكير')
            ->assertActionVisible('cancelActivationReminder');
        $notifier = app(Notifier::class);

        $this->assertSame(0, $notifier->activationReminders(), 'not before the end');
        $this->travel(3)->days();
        $this->travel(1)->minutes();
        $this->assertSame(2, $notifier->activationReminders());
        $this->assertSame(0, $notifier->activationReminders(), 'once');

        $sent = WhatsappOutbox::orderBy('id')->get();
        $this->assertSame([$ali->phone, $omar->phone], $sent->pluck('to_phone')->all());
        $this->assertSame('alert_activation', $sent->first()->kind);
        $this->assertStringContainsString('⏰ تذكير: مرتضى سليم (FBG876F31P12FO)', $sent->first()->body);
        $this->assertStringContainsString('فعّل مرتضى سليم 7 أيام', $sent->first()->body);
        $this->assertStringContainsString('رقم البرنامج: 41', $sent->first()->body);
    }

    public function test_a_renewal_moves_it_to_the_new_end_and_a_cancelled_one_never_goes(): void
    {
        $this->numbers();
        $account = $this->account('حسن جبار');
        $account->update(['external_ends_at' => now()->addDay()]);
        $reminders = app(ActivationReminderService::class);

        $reminders->set($account);
        // Renewed before it ended: waits for the new end.
        $account->update(['external_ends_at' => now()->addDays(8)]);
        $this->travel(2)->days();
        $this->assertSame(0, app(Notifier::class)->activationReminders());
        $this->travel(7)->days();
        $this->assertSame(3, app(Notifier::class)->activationReminders(), 'none chosen: all the authorized numbers');

        $other = $this->account('زيد علي');
        $other->update(['external_ends_at' => now()->addDay()]);
        $r = $reminders->set($other);
        $reminders->cancel($r);
        $this->travel(2)->days();
        $this->assertSame(0, app(Notifier::class)->activationReminders());
        $this->assertNotNull($r->fresh()->cancelled_at);
    }

    public function test_an_expired_subscription_is_reminded_at_once_and_a_new_reminder_replaces_the_old(): void
    {
        [$ali] = $this->numbers();
        $account = $this->account('علي كريم');
        $account->update(['external_ends_at' => now()->subHour()]);
        $reminders = app(ActivationReminderService::class);

        $first = $reminders->set($account);
        $reminders->set($account, [$ali->id]);
        $this->assertNotNull($first->fresh()->cancelled_at);
        $this->assertSame(1, ActivationReminder::whereNull('cancelled_at')->count());
        $this->assertSame(1, app(Notifier::class)->activationReminders());
        $this->assertSame([$ali->phone], WhatsappOutbox::pluck('to_phone')->all());
        $this->assertStringNotContainsString('رقم البرنامج', WhatsappOutbox::sole()->body, 'no line when the field is empty');
    }

    public function test_it_needs_its_permission(): void
    {
        $this->numbers();
        $account = $this->account('سجاد');
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $employee->forceFill(['denied_permissions' => ['reminders.activation']])->save();
        $this->actingAs($employee->fresh());

        $this->expectException(AuthorizationException::class);
        app(ActivationReminderService::class)->set($account);
    }
}
