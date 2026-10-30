<?php

namespace Tests\Feature;

use App\Filament\Pages\WhatsappTest;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsappOutbox;
use App\WhatsApp\Outbox;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\FakeWhatsApp;
use Tests\TestCase;

class WhatsAppTestPageTest extends TestCase
{
    public function test_a_template_is_sent_to_any_number_with_made_up_data(): void
    {
        $this->account('علي حسين'); // a real subscriber stays untouched
        $counts = [Account::count(), Debt::count()];

        Livewire::test(WhatsappTest::class)->assertOk()
            ->set('event', 'expired')->set('name', 'أحمد تجريبي')->set('phone', '07701234567')
            ->assertSee('أحمد تجريبي')
            ->call('send')->assertNotified();

        $message = WhatsappOutbox::sole();
        $this->assertSame(['alert_test', '9647701234567', null], [$message->kind, $message->to_phone, $message->account_id]);
        $template = MessageTemplate::where('auto_event', 'expired')->first();
        $this->assertStringContainsString('أحمد تجريبي', $message->body);
        $this->assertStringContainsString(trim(explode('{', $template->body)[0]), $message->body);
        $this->assertSame($counts, [Account::count(), Debt::count()]);
        $this->assertTrue(AuditLog::where('action', 'whatsapp.test_sent')->exists());

        // The same test may be sent again (each send is its own message).
        Livewire::test(WhatsappTest::class)->set('phone', '07701234567')->call('send');
        $this->assertSame(2, WhatsappOutbox::count());
    }

    public function test_the_subscriber_line_waits_for_the_day_and_a_free_text_works(): void
    {
        Livewire::test(WhatsappTest::class)
            ->set('event', 'custom')->set('custom', 'مرحبا {الاسم}، باقي {الأيام} يوم')->set('days', '٣')
            ->set('line', 'subscriber')->set('phone', '9647701234567')->call('send');

        $message = WhatsappOutbox::sole();
        $this->assertSame(['sub_test', 'مرحبا مشترك تجريبي، باقي 3 يوم'], [$message->kind, $message->body]);

        $fake = new FakeWhatsApp;
        app(Outbox::class)->dispatch($fake, CarbonImmutable::parse('2026-09-21 23:30'));
        $this->assertSame([], $fake->sent, 'as a subscriber gets it: not at night');
        app(Outbox::class)->dispatch($fake, CarbonImmutable::parse('2026-09-22 10:00'));
        $this->assertCount(1, $fake->sent);
    }

    public function test_a_wrong_number_queues_nothing_and_employees_cannot_open_it(): void
    {
        Livewire::test(WhatsappTest::class)->set('phone', '123')->call('send')->assertNotified();
        $this->assertSame(0, WhatsappOutbox::count());

        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);
        $this->get(WhatsappTest::getUrl())->assertForbidden();
    }
}
