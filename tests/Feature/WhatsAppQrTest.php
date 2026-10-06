<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\PaymentMethod;
use App\Filament\Pages\WhatsappReminders;
use App\Filament\Pages\WhatsappSettings;
use App\Filament\Resources\WhatsappOutbox\Pages\ListWhatsappOutbox;
use App\Models\Account;
use App\Models\MessageTemplate;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutbox;
use App\Services\ActivationService;
use App\Services\PaymentService;
use App\Services\RenewalService;
use App\Services\Settings;
use App\WhatsApp\CloudApiGateway;
use App\WhatsApp\Gateway;
use App\WhatsApp\Notifier;
use App\WhatsApp\NumberRegistry;
use App\WhatsApp\Outbox;
use App\WhatsApp\Transcriber;
use App\WhatsApp\WahaGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeWhatsApp;
use Tests\TestCase;

class WhatsAppQrTest extends TestCase
{
    private const ADMIN_PHONE = '9647701111111';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'whatsapp.waha.url' => 'http://waha:3000', 'whatsapp.waha.api_key' => 'waha-key',
            'whatsapp.waha.webhook_secret' => 'hook-secret', 'whatsapp.send_delay' => [0, 0],
        ]);
        $this->withoutDefer();
    }

    private string $wahaStatus = 'WORKING';

    private bool $wahaExists = true;

    private bool $faked = false;

    /** One fake for the whole test; later calls only change what the session looks like. */
    private function fakeWaha(string $status = 'WORKING', bool $exists = true): void
    {
        [$this->wahaStatus, $this->wahaExists] = [$status, $exists];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            [$status, $exists] = [$this->wahaStatus, $this->wahaExists];
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/api/sendText') => Http::response(['id' => 'true_x@c.us_ABC']),
                str_contains($url, '/api/default/auth/qr') => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
                str_contains($url, '/api/files/') => Http::response('OGG', 200, ['Content-Type' => 'audio/ogg']),
                str_ends_with($url, '/api/sessions/default') && $request->method() === 'GET' => $exists
                    ? Http::response(['name' => 'default', 'status' => $status, 'me' => $status === 'WORKING' ? ['id' => '9647705550000@c.us', 'pushName' => 'المحل'] : null])
                    : Http::response(['message' => 'not found'], 404),
                default => Http::response(['name' => 'default', 'status' => 'STARTING']),
            };
        });
    }

    private function postEvent(array $event, ?string $signature = null)
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/whatsapp/qr-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => $signature ?? hash_hmac('sha512', $body, 'hook-secret'),
            'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
        ], $body);
    }

    private function message(array $payload): array
    {
        return ['id' => 'evt1', 'event' => 'message', 'session' => 'default', 'payload' => $payload + [
            'id' => 'false_'.self::ADMIN_PHONE.'@c.us_'.uniqid(), 'timestamp' => now()->getTimestamp(),
            'from' => self::ADMIN_PHONE.'@c.us', 'fromMe' => false, 'hasMedia' => false, 'body' => '',
        ]];
    }

    private function enableCommands(): void
    {
        app(Settings::class)->set('whatsapp.enabled', true);
        app(NumberRegistry::class)->add('07701111111', $this->admin, 'المدير');
    }

    private function renewed(string $name = 'زينب كريم', int $days = 7): Account
    {
        $account = $this->account($name, phone: '0790'.random_int(1000000, 9999999));
        $ends = now()->addDays($days)->addHour();
        $account->update(['current_plan_id' => $this->plan()->id, 'external_ends_at' => $ends, 'company_days_left' => $days]);
        app(RenewalService::class)->record($account, 0, $days, null, CarbonImmutable::parse($ends));

        return $account->fresh();
    }

    // ---- Linking and receiving ----

    public function test_the_qr_connection_is_the_default_gateway(): void
    {
        $this->assertInstanceOf(WahaGateway::class, app(Gateway::class));
        app(Settings::class)->set('whatsapp.driver', 'meta');
        $this->assertInstanceOf(CloudApiGateway::class, app(Gateway::class));
    }

    public function test_linking_creates_the_session_with_a_signed_webhook_and_shows_the_qr(): void
    {
        $this->fakeWaha(exists: false);

        Livewire::test(WhatsappSettings::class)->assertSee('غير مربوط')->call('linkPhone');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/sessions')
            && $r->header('X-Api-Key')[0] === 'waha-key'
            && $r['name'] === 'default' && $r['start'] === true
            && $r['config']['webhooks'][0]['url'] === 'http://app:8080/whatsapp/qr-webhook'
            && $r['config']['webhooks'][0]['hmac']['key'] === 'hook-secret'
            && $r['config']['webhooks'][0]['events'] === ['message']);

        $this->fakeWaha('SCAN_QR_CODE');
        Livewire::test(WhatsappSettings::class)->assertSee('بانتظار مسح الباركود')->assertSee(route('whatsapp.qr'), escape: false);
        $this->get(route('whatsapp.qr'))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->fakeWaha('WORKING');
        Livewire::test(WhatsappSettings::class)->assertSee('متصل')->assertSee('9647705550000')->call('unlinkPhone');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sessions/default/logout'));
    }

    public function test_an_unsigned_qr_webhook_is_refused(): void
    {
        $this->enableCommands();
        $this->postEvent($this->message(['body' => 'الديون الثانوية']), 'forged')->assertStatus(401);

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_a_command_from_the_linked_phone_runs_and_the_reply_goes_back_through_it(): void
    {
        $this->fakeWaha();
        $this->enableCommands();

        $this->postEvent($this->message(['body' => 'الديون الثانوية']))->assertOk();

        $message = WhatsappMessage::sole();
        $this->assertSame(['done', self::ADMIN_PHONE, 'الديون الثانوية'], [$message->status, $message->from_phone, $message->body]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText')
            && $r['chatId'] === self::ADMIN_PHONE.'@c.us' && str_contains($r['text'], 'الديون الثانوية'));
    }

    public function test_own_group_and_hidden_number_messages_are_ignored(): void
    {
        $this->fakeWaha();
        $this->enableCommands();

        $this->postEvent($this->message(['body' => 'الديون الثانوية', 'fromMe' => true]));
        $this->postEvent($this->message(['body' => 'الديون الثانوية', 'from' => '120363@g.us']));
        $this->postEvent($this->message(['body' => 'الديون الثانوية', 'from' => '99887766@lid']));
        $this->assertSame(0, WhatsappMessage::count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText'));

        // A hidden id with the real number beside it is accepted.
        $this->postEvent($this->message(['body' => 'الديون الثانوية', 'from' => '99887766@lid', '_data' => ['key' => ['remoteJidAlt' => self::ADMIN_PHONE.'@s.whatsapp.net']]]));
        $this->assertSame('done', WhatsappMessage::sole()->status);
    }

    public function test_a_voice_note_from_the_linked_phone_is_downloaded_from_waha_and_transcribed(): void
    {
        $this->fakeWaha();
        $this->enableCommands();
        $fake = new FakeWhatsApp;
        $fake->transcript = 'الديون الثانوية';
        $this->app->instance(Transcriber::class, $fake);

        $this->postEvent($this->message(['hasMedia' => true, 'media' => ['url' => 'http://waha:3000/api/files/default/voice.oga', 'mimetype' => 'audio/ogg; codecs=opus']]));

        $this->assertSame(['OGG'], $fake->transcribed);
        $this->assertSame('الديون الثانوية', WhatsappMessage::sole()->transcript);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://waha:3000/api/files/default/voice.oga' && $r->header('X-Api-Key')[0] === 'waha-key');
    }

    public function test_a_message_cut_off_mid_way_is_closed_and_the_sender_told(): void
    {
        $number = app(NumberRegistry::class)->add('07701111111', $this->admin, 'المدير');
        $stuck = WhatsappMessage::create(['wa_message_id' => 'w-stuck', 'from_phone' => self::ADMIN_PHONE, 'whatsapp_number_id' => $number->id,
            'type' => 'audio', 'status' => 'received', 'received_at' => now()->subMinutes(15)]);
        WhatsappMessage::create(['wa_message_id' => 'w-fresh', 'from_phone' => self::ADMIN_PHONE, 'type' => 'audio', 'status' => 'received', 'received_at' => now()->subMinute()]);

        $this->artisan('whatsapp:scan')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('received', WhatsappMessage::where('wa_message_id', 'w-fresh')->value('status'), 'still within its time');
        $reply = WhatsappOutbox::sole();
        $this->assertSame(self::ADMIN_PHONE, $reply->to_phone);
        $this->assertStringContainsString('رسالتك الصوتية', $reply->body);
    }

    // ---- Staff alerts ----

    public function test_paying_a_secondary_debt_alerts_the_alert_numbers_and_the_queue_sends_it(): void
    {
        app(NumberRegistry::class)->add('07702222222', $this->admin, 'المحل', alerts: true);
        app(NumberRegistry::class)->add('07703333333', $this->admin, 'بدون تنبيهات');
        $account = $this->renewed();

        app(PaymentService::class)->record($account, 35000, PaymentMethod::Cash, $this->cash());

        $alert = WhatsappOutbox::sole();
        $this->assertSame(['alert_must_activate', '9647702222222', 'pending'], [$alert->kind, $alert->to_phone, $alert->status]);
        $this->assertStringContainsString('يجب التفعيل', $alert->body);
        $this->assertStringContainsString('زينب كريم', $alert->body);

        $fake = new FakeWhatsApp;
        [$sent, $failed] = app(Outbox::class)->dispatch($fake);
        $this->assertSame([1, 0], [$sent, $failed]);
        $this->assertSame([['to' => '9647702222222', 'text' => $alert->body]], $fake->sent);
        $this->assertSame('sent', $alert->fresh()->status);
    }

    public function test_a_secondary_debt_about_to_end_is_alerted_once(): void
    {
        app(NumberRegistry::class)->add('07702222222', $this->admin, 'المحل', alerts: true);
        $account = $this->renewed();
        $account->update(['external_ends_at' => now()->addHours(10)]);

        $this->assertSame(1, app(Notifier::class)->scanSecondaryExpiring());
        $this->assertSame(0, app(Notifier::class)->scanSecondaryExpiring(), 'never twice for the same end date');

        $alert = WhatsappOutbox::sole();
        $this->assertSame('alert_secondary_expiring', $alert->kind);
        $this->assertStringContainsString('باقي 10 ساعة', $alert->body);

        app(Settings::class)->set('alerts.secondary_expiring', false);
        $this->renewed('علي حسين')->update(['external_ends_at' => now()->addHours(5)]);
        $this->assertSame(0, app(Notifier::class)->scanSecondaryExpiring());
    }

    // ---- Subscriber messages ----

    public function test_subscriber_messages_are_off_until_switched_on(): void
    {
        $this->renewed();
        $this->assertSame(0, WhatsappOutbox::count());

        app(Settings::class)->set('subscriber_messages.enabled', true);
        $account = $this->renewed('علي حسين');

        $message = WhatsappOutbox::sole();
        $this->assertSame(['sub_renewal', $account->id], [$message->kind, $message->account_id]);
        $this->assertStringContainsString('علي حسين', $message->body);
        $this->assertStringContainsString('35,000', $message->body);

        app(Settings::class)->set('subscriber_messages.renewal', false);
        $this->renewed('حسن جبار');
        $this->assertSame(1, WhatsappOutbox::count());
    }

    public function test_expiring_expired_and_debt_messages_go_once_each(): void
    {
        app(Settings::class)->set('subscriber_messages.enabled', true);
        app(Settings::class)->set('subscriber_messages.renewal', false);
        $soon = $this->account('قريب', phone: '07901110001');
        $soon->update(['external_ends_at' => now()->addHours(20)]);
        $ended = $this->account('منتهي', phone: '07901110002');
        $ended->update(['external_ends_at' => now()->subHours(3)]);
        $owes = $this->renewed('مدين', days: 5);
        $this->travelTo(now()->addDays(2));
        $soon->update(['external_ends_at' => now()->addHours(20)]);
        $ended->update(['external_ends_at' => now()->subHours(3)]);

        $notifier = app(Notifier::class);
        $notifier->scanSubscribers();
        $notifier->scanSubscribers();

        $this->assertEqualsCanonicalizing(['sub_expiring', 'sub_expired', 'sub_debt'], WhatsappOutbox::pluck('kind')->all());
        $this->assertSame($owes->id, WhatsappOutbox::where('kind', 'sub_debt')->value('account_id'));
        $this->assertStringContainsString('انتهى اشتراككم', WhatsappOutbox::where('kind', 'sub_expired')->value('body'));
    }

    public function test_subscribers_are_not_messaged_at_night_but_staff_alerts_are(): void
    {
        $outbox = app(Outbox::class);
        $outbox->queue('07901110001', 'رسالة مشترك', 'sub_expiring');
        $outbox->queue('07702222222', 'تنبيه', 'alert_must_activate');
        $fake = new FakeWhatsApp;

        $outbox->dispatch($fake, CarbonImmutable::parse('2026-09-21 23:30'));
        $this->assertSame(['تنبيه'], array_column($fake->sent, 'text'));

        $outbox->dispatch($fake, CarbonImmutable::parse('2026-09-22 10:00'));
        $this->assertSame(['تنبيه', 'رسالة مشترك'], array_column($fake->sent, 'text'));

        app(Settings::class)->set('whatsapp.daily_limit', 1); // one already sent today
        $outbox->queue('07901110003', 'ثالثة', 'reminder');
        $outbox->dispatch($fake, CarbonImmutable::parse('2026-09-22 11:00'));
        $this->assertCount(2, $fake->sent, 'the daily cap holds');
    }

    public function test_reminders_can_be_sent_from_the_linked_phone(): void
    {
        $account = $this->renewed('زينب كريم', days: 3);
        $template = MessageTemplate::where('auto_event', 'expiring')->sole();

        Livewire::test(WhatsappReminders::class, ['preselect' => $account->id])
            ->set('template', $template->id)
            ->assertSee('إرسال من الهاتف المربوط')
            ->call('sendFromPhone');

        $message = WhatsappOutbox::where('kind', 'reminder')->sole();
        $this->assertSame([$account->id, $this->admin->id], [$message->account_id, $message->created_by]);
        $this->assertStringContainsString('زينب كريم', $message->body);

        Livewire::test(ListWhatsappOutbox::class)->assertOk()->assertSee('زينب كريم');
    }

    public function test_every_activation_messages_the_subscriber_with_the_new_end_date(): void
    {
        app(Settings::class)->set('subscriber_messages.enabled', true);

        // In the panel (7 or 30 days).
        $panel = $this->account('سارة علي', phone: '07901110010');
        $activation = app(ActivationService::class)->activate($panel, $this->plan(), ActivationKind::Full30);
        $message = WhatsappOutbox::where('account_id', $panel->id)->sole();
        $this->assertSame(['sub_renewal', '9647901110010'], [$message->kind, $message->to_phone]);
        $this->assertStringContainsString($activation->ends_at->format('Y/m/d'), $message->body);

        // By WhatsApp: the message carries the new end date, not the old one.
        $this->enableCommands();
        $wa = $this->account('كرار حسن', phone: '07901110011');
        $wa->update(['current_plan_id' => $this->plan()->id, 'external_ends_at' => now()->subDays(3)]);
        $fake = new FakeWhatsApp;
        $this->app->instance(Gateway::class, $fake);
        $this->postEvent($this->message(['body' => 'كرار حسن فعلته سبع ايام']));
        $body = WhatsappOutbox::where('account_id', $wa->id)->sole()->body;
        $this->assertStringContainsString(now()->addDays(7)->format('Y/m/d'), $body);
    }

    public function test_only_the_30_day_subscribers_are_told_before_and_at_the_end(): void
    {
        app(Settings::class)->set('subscriber_messages.enabled', true);
        app(Settings::class)->set('subscriber_messages.renewal', false);
        app(Settings::class)->set('subscriber_messages.debt', false);
        $short = $this->renewed('قصير', days: 7);
        $full = $this->renewed('كامل', days: 30);
        $completed = $this->renewed('مكمل', days: 7);

        $this->travelTo(now()->addDays(6)->addHours(10));
        // The 7-day activation was completed to 30 days on the company site.
        $completed->update(['external_ends_at' => now()->addHours(14)->addDays(23)]);
        $notifier = app(Notifier::class);
        $notifier->scanSubscribers();
        $this->assertSame(0, WhatsappOutbox::count(), 'the 7-day subscriber is not told it ends');

        $this->travelTo(now()->addDays(23)->addHours(10));
        $notifier->scanSubscribers();
        $this->assertEqualsCanonicalizing([$full->id, $completed->id], WhatsappOutbox::where('kind', 'sub_expiring')->pluck('account_id')->all());

        $this->travelTo(now()->addDays(1));
        $notifier->scanSubscribers();
        $this->assertEqualsCanonicalizing([$full->id, $completed->id], WhatsappOutbox::where('kind', 'sub_expired')->pluck('account_id')->all());
        $this->assertFalse(WhatsappOutbox::where('account_id', $short->id)->exists());
    }

    public function test_a_second_phone_sends_the_subscribers_messages_and_the_first_keeps_the_staffs(): void
    {
        $outbox = app(Outbox::class);
        $outbox->queue('07901110001', 'رسالة مشترك', 'sub_expiring');
        $outbox->queue('07901110002', 'تذكير يدوي', 'reminder');
        $outbox->queue('07702222222', 'تنبيه', 'alert_must_activate');
        $at = CarbonImmutable::parse('2026-09-22 10:00');

        app(Settings::class)->set('whatsapp.notify_separate', true);
        [$main, $notify] = [new FakeWhatsApp, new FakeWhatsApp];
        $outbox->dispatch($main, $at, 'main');
        $outbox->dispatch($notify, $at, 'notify');
        $this->assertSame(['تنبيه'], array_column($main->sent, 'text'));
        $this->assertSame(['رسالة مشترك', 'تذكير يدوي'], array_column($notify->sent, 'text'));

        // Through the scheduler: each line from its own WAHA service; nothing waits on the other.
        $this->fakeWaha();
        config(['whatsapp.waha_notify.url' => 'http://waha-notify:3000', 'whatsapp.waha_notify.api_key' => 'waha-key']);
        $outbox->queue('07901110003', 'مشترك ثاني', 'sub_debt');
        $outbox->queue('07702222222', 'تنبيه ثاني', 'alert_must_activate');
        $this->artisan('whatsapp:dispatch')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r->url() === 'http://waha-notify:3000/api/sendText' && $r['text'] === 'مشترك ثاني');
        Http::assertSent(fn (Request $r) => $r->url() === 'http://waha:3000/api/sendText' && $r['text'] === 'تنبيه ثاني');
        Http::assertNotSent(fn (Request $r) => $r->url() === 'http://waha:3000/api/sendText' && $r['text'] === 'مشترك ثاني');
    }

    public function test_the_second_phone_is_linked_from_the_settings_without_a_webhook(): void
    {
        $this->fakeWaha('MISSING', exists: false);
        config(['whatsapp.waha_notify.api_key' => 'waha-key']);
        Livewire::test(WhatsappSettings::class)->assertDontSeeHtml("linkPhone('notify')")
            ->fillForm(['notify_separate' => true])->call('save');
        $this->assertTrue((bool) app(Settings::class)->get('whatsapp.notify_separate'));

        Livewire::test(WhatsappSettings::class)->assertSeeHtml("linkPhone('notify')")->call('linkPhone', 'notify');
        Http::assertSent(fn (Request $r) => $r->url() === 'http://waha-notify:3000/api/sessions' && $r->method() === 'POST' && $r['config']['webhooks'] === []);
        Http::assertNotSent(fn (Request $r) => $r->url() === 'http://waha:3000/api/sessions' && $r->method() === 'POST');

        $this->get(route('whatsapp.qr', ['line' => 'notify']))->assertOk();
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://waha-notify:3000/api/default/auth/qr'));
    }

    public function test_settings_switches_are_saved(): void
    {
        $this->fakeWaha();
        Livewire::test(WhatsappSettings::class)
            ->fillForm(['sub_enabled' => true, 'sub_debt' => false, 'alert_hours_before' => 12, 'send_from_hour' => 10])
            ->call('save');

        $settings = app(Settings::class);
        $this->assertTrue((bool) $settings->get('subscriber_messages.enabled'));
        $this->assertFalse((bool) $settings->get('subscriber_messages.debt'));
        $this->assertSame([12, 10], [$settings->get('alerts.hours_before'), $settings->get('whatsapp.send_from_hour')]);
    }
}
