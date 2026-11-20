<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CustodyReminder;
use App\Models\User;
use App\Models\WhatsappOutbox;
use App\Services\EmployeeFinance;
use App\Services\Settings;
use App\Services\TreasuryService;
use App\WhatsApp\ClaudeInterpreter;
use App\WhatsApp\CustodyReminders;
use App\WhatsApp\Gateway;
use App\WhatsApp\NumberRegistry;
use App\WhatsApp\Transcriber;
use App\WhatsApp\WahaInbox;
use Carbon\CarbonImmutable;
use Tests\Support\FakeClaude;
use Tests\Support\FakeWhatsApp;
use Tests\TestCase;

/** «تذكير تنزيل العهدة»: a daily WhatsApp message per employee custody; «نعم» or an amount hands it over. */
class CustodyReminderTest extends TestCase
{
    private const ADMIN_PHONE = '9647701111111';

    private FakeWhatsApp $wa;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wa = new FakeWhatsApp;
        $this->app->instance(Gateway::class, $this->wa);
        $this->app->instance(Transcriber::class, $this->wa);
        $this->app->instance(ClaudeInterpreter::class, new FakeClaude);
        app(Settings::class)->set('whatsapp.enabled', true);
        app(NumberRegistry::class)->add('07701111111', $this->admin, 'هاتف المدير');
        $this->withoutDefer();
    }

    private function employee(string $name, int $custody): User
    {
        $user = User::factory()->create(['name' => $name, 'branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');
        app(EmployeeFinance::class)->fundCustody($user, $custody, $this->cash());

        return $user;
    }

    /** A WAHA message from the admin's phone, optionally a «رد» on another message. */
    private function reply(string $body, ?string $quoted = null): void
    {
        app(WahaInbox::class)->handle(['event' => 'message', 'payload' => array_filter([
            'id' => 'waha.'.++$this->sequence, 'from' => self::ADMIN_PHONE.'@c.us', 'fromMe' => false,
            'timestamp' => now()->getTimestamp(), 'body' => $body,
            'replyTo' => $quoted !== null ? ['id' => 'x', 'body' => $quoted] : null,
        ])]);
    }

    private function at(int $hour): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->setTime($hour, 0);
    }

    public function test_the_admin_is_reminded_daily_and_hands_custody_over_by_reply(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 500000);
        $ali = $this->employee('علي الجابي', 150000);
        $hasan = $this->employee('حسن الفني', 80000);
        $this->employee('بلا عهدة', 1000);
        app(EmployeeFinance::class)->handOver(User::where('name', 'بلا عهدة')->sole(), 1000, $this->cash());
        $reminders = app(CustodyReminders::class);
        $finance = app(EmployeeFinance::class);

        // Not before 9 in the morning; at 9 one message per employee holding custody, once a day.
        $this->assertSame(0, $reminders->queueDue($this->at(8)));
        $this->assertSame(2, $reminders->queueDue($this->at(9)));
        $this->assertSame(0, $reminders->queueDue($this->at(12)));
        $messages = WhatsappOutbox::where('kind', 'alert_custody')->orderBy('id')->get();
        $this->assertSame([self::ADMIN_PHONE, self::ADMIN_PHONE], $messages->pluck('to_phone')->all());
        $this->assertStringContainsString('عهدة علي الجابي: 150,000', $messages[0]->body);
        $this->assertStringContainsString('عهدة حسن الفني: 80,000', $messages[1]->body);

        // A «رد» on علي's message (not the latest) with more than he holds: refused, nothing moves.
        $this->reply('200 الف', $messages[0]->body);
        $this->assertStringContainsString('المبلغ أكبر من عهدة علي الجابي', $this->wa->lastReply());
        $this->assertSame(150000, $finance->custodyBalance($ali));

        // The same «رد» with a smaller amount: just that much, from علي.
        $this->reply('100 الف', $messages[0]->body);
        $this->assertStringContainsString('✅ نزلت من عهدة علي الجابي 100,000', $this->wa->lastReply());
        $this->assertStringContainsString('باقي بعهدته: 50,000', $this->wa->lastReply());

        // «نعم» without «رد»: the latest one still open (حسن), all of it, into the main cash box.
        $this->reply('نعم');
        $this->assertStringContainsString('✅ نزلت من عهدة حسن الفني 80,000', $this->wa->lastReply());
        $this->assertSame([50000, 0, 450000], [$finance->custodyBalance($ali), $finance->custodyBalance($hasan), app(TreasuryService::class)->balance($this->cash())]);
        $this->assertSame(['done', 'done'], CustodyReminder::orderBy('id')->pluck('status')->all());
        $this->assertSame(2, AuditLog::where('action', 'custody.settled')->where('source', 'whatsapp')->count());

        // Answered once; a «نعم» with nothing open is an ordinary message again.
        $this->reply('نعم', $messages[0]->body);
        $this->assertStringContainsString('انجاوب قبل', $this->wa->lastReply());
        $this->assertSame(50000, $finance->custodyBalance($ali));

        // Next day a new reminder with what is left.
        $this->assertSame(1, $reminders->queueDue($this->at(9)->addDay()));
        $this->assertStringContainsString('عهدة علي الجابي: 50,000', WhatsappOutbox::where('kind', 'alert_custody')->latest('id')->first()->body);
    }

    public function test_only_admin_numbers_get_it_and_it_can_be_switched_off(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 500000);
        $ali = $this->employee('علي الجابي', 50000);
        app(NumberRegistry::class)->add('07702222222', $ali, 'هاتف علي');

        $this->assertSame(1, app(CustodyReminders::class)->queueDue($this->at(10)));
        $this->assertSame([self::ADMIN_PHONE], WhatsappOutbox::where('kind', 'alert_custody')->pluck('to_phone')->all());

        app(Settings::class)->set('alerts.custody', false);
        $this->assertSame(0, app(CustodyReminders::class)->queueDue($this->at(10)->addDay()));
    }

    public function test_the_check_command_tells_why_a_reminder_went_or_not(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 500000);
        $this->employee('علي الجابي', 50000);
        app(CustodyReminders::class)->queueDue($this->at(10));

        $this->artisan('custody:check')->assertExitCode(0)
            ->expectsOutputToContain('التذكير مشغّل: نعم')
            ->expectsOutputToContain(self::ADMIN_PHONE)
            ->expectsOutputToContain('علي الجابي: 50,000')
            ->expectsOutputToContain('علي الجابي: sent · الرسالة: pending');
    }

    public function test_asking_for_a_custody_by_whatsapp_then_1_or_an_amount(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 500000);
        $ahmed = $this->employee('أحمد الجابي', 120000);
        $this->employee('أحمد علي', 1000);
        $finance = app(EmployeeFinance::class);

        // Two «أحمد»: asked for the full name; nothing opens.
        $this->reply('تنزيل عهده احمد');
        $this->assertStringContainsString('اكتب الاسم كامل', $this->wa->lastReply());
        $this->assertSame(0, CustodyReminder::count());

        $this->reply('تنزيل عهدة احمد الجابي');
        $this->assertStringContainsString('💼 عهدة أحمد الجابي: 120,000', $this->wa->lastReply());
        $this->assertStringContainsString('ارسل 1 تنزل كاملة', $this->wa->lastReply());

        // An amount: just that much.
        $this->reply('20 الف');
        $this->assertStringContainsString('✅ نزلت من عهدة أحمد الجابي 20,000', $this->wa->lastReply());
        $this->assertSame(100000, $finance->custodyBalance($ahmed));

        // Asked again (beside today's daily reminder too), «1» hands over all of it.
        app(CustodyReminders::class)->queueDue($this->at(10));
        $this->reply('نزل عهدة الجابي');
        $this->assertStringContainsString('عهدة أحمد الجابي: 100,000', $this->wa->lastReply());
        $this->reply('1');
        $this->assertStringContainsString('ما بقى شي بعهدته', $this->wa->lastReply());
        $this->assertSame(0, $finance->custodyBalance($ahmed));
        $this->assertSame(499000, app(TreasuryService::class)->balance($this->cash()));

        // Nothing left; an unknown name.
        $this->reply('تنزيل عهده احمد الجابي');
        $this->assertStringContainsString('ما اكو شي ينزل', $this->wa->lastReply());
        $this->reply('تنزيل عهده زيد');
        $this->assertStringContainsString('ما لكيت موظف عنده عهدة', $this->wa->lastReply());
    }
}
