<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Filament\Pages\WhatsappSettings;
use App\Filament\Resources\WhatsappMessages\Pages\ListWhatsappMessages;
use App\Filament\Resources\WhatsappNumbers\Pages\ManageWhatsappNumbers;
use App\Models\Account;
use App\Models\AccountRenewal;
use App\Models\AuditLog;
use App\Models\Debt;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappNumber;
use App\Services\EmployeeFinance;
use App\Services\Ledger;
use App\Services\Settings;
use App\Services\TreasuryService;
use App\WhatsApp\ClaudeInterpreter;
use App\WhatsApp\Command;
use App\WhatsApp\Gateway;
use App\WhatsApp\NumberRegistry;
use App\WhatsApp\RuleInterpreter;
use App\WhatsApp\Transcriber;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\FakeClaude;
use Tests\Support\FakeWhatsApp;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    private const ADMIN_PHONE = '9647701111111';

    private const STRANGER = '9647709999999';

    private FakeWhatsApp $wa;

    private FakeClaude $claude;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wa = new FakeWhatsApp;
        $this->claude = new FakeClaude;
        $this->app->instance(Gateway::class, $this->wa);
        $this->app->instance(Transcriber::class, $this->wa);
        $this->app->instance(ClaudeInterpreter::class, $this->claude);
        config(['whatsapp.app_secret' => 'test-app-secret', 'whatsapp.verify_token' => 'verify-me']);
        app(Settings::class)->set('whatsapp.enabled', true);
        app(NumberRegistry::class)->add('07701111111', $this->admin, 'هاتف المدير');
        $this->withoutDefer();
    }

    private function deliver(array $message, ?string $signature = null)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [[
            'field' => 'messages',
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '123'], 'messages' => [$message]],
        ]]]]], JSON_UNESCAPED_UNICODE);
        $signature ??= 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');

        return $this->call('POST', '/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
    }

    private function text(string $body, string $from = self::ADMIN_PHONE, ?string $id = null)
    {
        return $this->deliver(['from' => $from, 'id' => $id ?? 'wamid.'.++$this->sequence, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $body]]);
    }

    private function voice(string $from = self::ADMIN_PHONE)
    {
        return $this->deliver(['from' => $from, 'id' => 'wamid.'.++$this->sequence, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'audio', 'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg; codecs=opus']]);
    }

    private function subscriber(string $name = 'محمد رمضان', string $plan = 'basic'): Account
    {
        $account = $this->account($name, phone: '0790'.random_int(1000000, 9999999));
        $account->update(['current_plan_id' => $this->plan($plan)->id]);

        return $account;
    }

    private function employee(string $phone, array $extra = []): User
    {
        $user = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');
        $user->givePermissionTo($extra);
        app(NumberRegistry::class)->add($phone, $user, 'هاتف أحمد');

        return $user;
    }

    // ---- Security: webhook, signature, authorized numbers ----

    public function test_meta_webhook_verification(): void
    {
        $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
    }

    public function test_a_delivery_without_a_valid_signature_is_refused(): void
    {
        $this->subscriber();
        $this->deliver(['from' => self::ADMIN_PHONE, 'id' => 'wamid.x', 'type' => 'text', 'text' => ['body' => 'محمد رمضان فعلته سبع ايام']], 'sha256=forged')->assertStatus(401);

        $this->assertSame(0, WhatsappMessage::count());
        $this->assertSame(0, AccountRenewal::count());
        $this->assertSame([], $this->wa->sent);
    }

    public function test_an_unauthorized_number_gets_only_the_refusal_and_nothing_is_read(): void
    {
        $this->subscriber();
        $this->text('محمد رمضان فعلته سبع ايام', from: self::STRANGER)->assertOk();
        $this->voice(from: self::STRANGER);

        $this->assertSame(0, AccountRenewal::count());
        $this->assertSame([], $this->claude->calls);
        $this->assertSame([], $this->wa->downloads, 'a voice note from an unknown number is never downloaded');
        $this->assertSame([], $this->wa->transcribed);
        $this->assertCount(2, $this->wa->sent);
        foreach ($this->wa->sent as $sent) {
            $this->assertSame(['to' => self::STRANGER, 'text' => 'هذا الرقم غير مصرح له باستخدام النظام.'], $sent);
        }
        $messages = WhatsappMessage::all();
        $this->assertSame(['unauthorized'], $messages->pluck('status')->unique()->values()->all());
        $this->assertNull($messages->first()->body, 'the content of an unauthorized message is not stored');
        $this->assertSame(2, AuditLog::where('action', 'whatsapp.unauthorized')->where('source', 'whatsapp')->count());
    }

    public function test_a_disabled_number_is_refused(): void
    {
        app(NumberRegistry::class)->setActive(WhatsappNumber::where('phone', self::ADMIN_PHONE)->sole(), false);
        $this->subscriber();

        $this->text('محمد رمضان فعلته سبع ايام');

        $this->assertSame(0, AccountRenewal::count());
        $this->assertSame('هذا الرقم غير مصرح له باستخدام النظام.', $this->wa->lastReply());
    }

    public function test_nothing_runs_while_the_whatsapp_panel_is_switched_off(): void
    {
        app(Settings::class)->set('whatsapp.enabled', false);
        $this->subscriber();

        $this->text('محمد رمضان فعلته سبع ايام');

        $this->assertSame(0, AccountRenewal::count());
        $this->assertSame([], $this->wa->sent);
        $this->assertSame('ignored', WhatsappMessage::sole()->status);
    }

    // ---- Activation ----

    public function test_activation_command_records_the_renewal_and_one_secondary_debt(): void
    {
        $account = $this->subscriber();

        $this->text('محمد رمضان فعلته سبع أيام')->assertOk();

        $renewal = AccountRenewal::sole();
        $this->assertSame('debt_created', $renewal->status);
        $this->assertSame(7, $renewal->new_days);
        $debt = Debt::sole();
        $this->assertSame(DebtBucket::Secondary, $debt->bucket);
        $this->assertSame(35000, $debt->balance);
        $this->assertSame($account->id, $debt->account_id);
        $this->assertSame(35000, $this->balanceOf(Ledger::AR_SECONDARY));
        $account->refresh();
        $this->assertTrue($account->external_ends_at->equalTo(now()->addDays(7)));
        // The next company sync sees 7 days as already known and does not count the renewal again.
        $this->assertSame(7, $account->company_days_left);

        $this->assertStringContainsString('محمد رمضان', $this->wa->lastReply());
        $this->assertStringContainsString('35,000', $this->wa->lastReply());
        $message = WhatsappMessage::sole();
        $this->assertSame(['done', 'activate', $this->admin->id], [$message->status, $message->intent, $message->user_id]);

        // Audited as WhatsApp, with the phone, the user, the command and what changed.
        $command = AuditLog::where('action', 'whatsapp.command')->sole();
        $this->assertSame(['whatsapp', $this->admin->id, $account->subscriber_id], [$command->source, $command->user_id, $command->subscriber_id]);
        $this->assertSame(self::ADMIN_PHONE, $command->new_values['phone']);
        $this->assertSame('محمد رمضان فعلته سبع أيام', $command->new_values['command']);
        $this->assertSame($debt->number, $command->new_values['changes']['debt']);
        $this->assertSame('whatsapp', AuditLog::where('action', 'renewal.detected')->sole()->source);
        $this->assertSame('whatsapp', AuditLog::where('action', 'debt.created')->sole()->source);
        $this->assertSame($this->admin->id, AuditLog::where('action', 'renewal.detected')->sole()->user_id);
    }

    public function test_a_company_sync_after_a_whatsapp_activation_does_not_add_a_second_debt(): void
    {
        $site = new \Tests\Support\FakeCompanyClient;
        $this->app->instance(\App\Sync\CompanyClient::class, $site);
        $record = ['customer_id' => '2825350', 'name' => 'محمد رمضان', 'plan' => 'BASIC', 'status' => 'Expired',
            'ends_at' => '2026-09-01T10:00:00Z', 'username' => 'FBG876F33P08', 'subscription_id' => '13525583'];
        $site->set($record);
        app(\App\Sync\CompanySync::class)->run('manual');

        $this->text('محمد رمضان فعلته سبع ايام');
        $this->assertSame(1, Debt::count());

        $this->travelTo(now()->addHour());
        $site->set(['status' => 'Active', 'ends_at' => now()->addDays(7)->subHour()->toIso8601String()] + $record);
        app(\App\Sync\CompanySync::class)->run('manual');

        $this->assertSame(1, Debt::count());
        $this->assertSame(1, AccountRenewal::count());
    }

    public function test_a_full_activation_is_recorded_without_a_debt(): void
    {
        $this->subscriber();

        $this->text('فعلت محمد رمضان شهر');

        $this->assertSame('activated', AccountRenewal::sole()->status);
        $this->assertSame(0, Debt::count());
        $this->assertStringContainsString('بدون دين', $this->wa->lastReply());
    }

    public function test_the_same_whatsapp_message_is_executed_only_once(): void
    {
        $this->subscriber();

        $this->text('محمد رمضان فعلته سبع ايام', id: 'wamid.same');
        $this->text('محمد رمضان فعلته سبع ايام', id: 'wamid.same');

        $this->assertSame(1, WhatsappMessage::count());
        $this->assertSame(1, Debt::count());
        $this->assertCount(1, $this->wa->sent);
    }

    public function test_repeating_an_activation_within_hours_does_not_create_a_second_debt(): void
    {
        $this->subscriber();

        $this->text('محمد رمضان فعلته سبع ايام');
        $this->travelTo(now()->addHours(3));
        $this->text('محمد رمضان فعلته سبع ايام');

        $this->assertSame(1, Debt::count());
        $this->assertStringContainsString('مسجل مسبقاً', $this->wa->lastReply());
    }

    public function test_a_voice_note_is_transcribed_and_executed(): void
    {
        $this->subscriber();
        $this->wa->transcript = 'محمد رمضان فعلته سبع ايام';

        $this->voice();

        $this->assertSame(['media-1'], $this->wa->downloads);
        $this->assertSame(1, Debt::count());
        $message = WhatsappMessage::sole();
        $this->assertSame(['done', 'محمد رمضان فعلته سبع ايام'], [$message->status, $message->transcript]);
        $this->assertTrue(AuditLog::where('action', 'whatsapp.command')->sole()->new_values['voice']);
    }

    public function test_voice_notes_can_be_switched_off(): void
    {
        app(Settings::class)->set('whatsapp.voice_enabled', false);

        $this->voice();

        $this->assertSame([], $this->wa->downloads);
        $this->assertStringContainsString('الصوتية متوقفة', $this->wa->lastReply());
    }

    public function test_an_unclear_activation_asks_for_the_days_and_the_answer_completes_it(): void
    {
        $this->subscriber();
        $this->claude->answer = new Command('activate', subscriber: 'محمد رمضان');

        $this->text('فعلت محمد رمضان');
        $this->assertSame('clarify', WhatsappMessage::latest('id')->first()->status);
        $this->assertStringContainsString('كم يوم', $this->wa->lastReply());
        $this->assertSame(0, Debt::count());

        $this->text("سبع ايام");

        $this->assertSame(1, Debt::count());
        $this->assertCount(1, $this->claude->calls, 'the short answer is completed without the AI');
    }

    public function test_an_unknown_or_ambiguous_subscriber_is_asked_about(): void
    {
        $this->subscriber('محمد رمضان علي');
        $this->subscriber('محمد رمضان حسن');

        $this->text('محمد رمضان فعلته سبع ايام');
        $this->assertStringContainsString('أكثر من مشترك', $this->wa->lastReply());

        $this->text('كرار فعلته سبع ايام');
        $this->assertStringContainsString('ما لقيت', $this->wa->lastReply());
        $this->assertSame(0, Debt::count());
    }

    // ---- Purchases, payments, debts ----

    public function test_a_purchase_of_several_items_records_each_expense(): void
    {
        app(TreasuryService::class)->openingBalance($this->cash(), 500000);
        $this->claude->answer = Command::fromArray(['intent' => 'purchase', 'items' => [
            ['description' => 'كيبل 30 متر', 'quantity' => 30, 'unit_price' => 5000, 'total' => null],
            ['description' => 'راوتر', 'quantity' => 1, 'unit_price' => null, 'total' => 40000],
        ]]);

        $this->text('شريت كيبل 30 متر سعر المتر 5 آلاف وراوتر بـ 40 الف');

        $expenses = Expense::with('category')->orderBy('id')->get();
        $this->assertSame([150000, 40000], $expenses->pluck('amount')->all());
        $this->assertSame(['كابلات ومواد', 'راوتر وأجهزة'], $expenses->pluck('category.name_ar')->all());
        $this->assertSame([$this->admin->id], $expenses->pluck('created_by')->unique()->values()->all());
        $this->assertSame(310000, app(TreasuryService::class)->balance($this->cash()));
        $this->assertStringContainsString('190,000', $this->wa->lastReply());
        $this->assertSame(2, AuditLog::where('action', 'expense.created')->where('source', 'whatsapp')->count());
    }

    public function test_a_purchase_item_without_a_price_is_asked_about(): void
    {
        $this->claude->answer = Command::fromArray(['intent' => 'purchase', 'items' => [['description' => 'راوتر', 'quantity' => 1]]]);

        $this->text('شريت راوتر');

        $this->assertSame(0, Expense::count());
        $this->assertStringContainsString('شكد سعر راوتر', $this->wa->lastReply());
    }

    public function test_a_payment_from_an_employee_lands_in_their_custody(): void
    {
        $this->subscriber();
        $this->text('محمد رمضان فعلته سبع ايام');
        $ahmed = $this->employee('07702222222');

        $this->text('محمد رمضان دفع 35 الف', from: '9647702222222');

        $payment = Payment::sole();
        $this->assertSame([35000, $ahmed->id], [$payment->amount, $payment->created_by]);
        $this->assertSame(35000, app(EmployeeFinance::class)->custodyBalance($ahmed));
        $this->assertSame(DebtStatus::Paid, Debt::sole()->status);
        $this->assertStringContainsString('المتبقي عليه: 0', $this->wa->lastReply());
    }

    public function test_deleting_a_debt_needs_the_permission(): void
    {
        $this->subscriber();
        $this->text('محمد رمضان فعلته سبع ايام');
        $this->employee('07702222222');

        $this->text('امسح دين محمد رمضان', from: '9647702222222');

        $this->assertSame(DebtStatus::Open, Debt::sole()->status);
        $this->assertSame('denied', WhatsappMessage::latest('id')->first()->status);
        $this->assertStringContainsString('صلاحية', $this->wa->lastReply());

        // The admin may.
        $this->text('امسح دين محمد رمضان');
        $this->assertSame(DebtStatus::Voided, Debt::sole()->status);
        $this->assertSame(0, $this->balanceOf(Ledger::AR_SECONDARY));
        $this->assertSame('whatsapp', AuditLog::where('action', 'like', 'debt.void%')->latest('id')->first()->source);
    }

    public function test_a_primary_debt_is_recorded_from_whatsapp(): void
    {
        $account = $this->subscriber();

        $this->text('سجل دين على محمد رمضان 25 الف');

        $debt = Debt::sole();
        $this->assertSame([DebtBucket::Primary, 25000, $account->id, \App\Enums\DebtSource::Manual], [$debt->bucket, $debt->balance, $debt->account_id, $debt->source]);
        $this->assertSame(25000, $this->balanceOf(Ledger::AR_PRIMARY));
        $this->assertStringContainsString('دين أولي', $this->wa->lastReply());
        $this->assertSame('whatsapp', AuditLog::where('action', 'debt.created')->sole()->source);

        $this->text('محمد رمضان عليه 10 الف دين ثانوي');
        $this->assertSame(DebtBucket::Secondary, Debt::latest('id')->first()->bucket);
    }

    public function test_recording_a_debt_needs_the_permission(): void
    {
        $this->subscriber();
        $this->employee('07702222222');

        $this->text('سجل دين على محمد رمضان 25 الف', from: '9647702222222');

        $this->assertSame(0, Debt::count());
        $this->assertSame('denied', WhatsappMessage::sole()->status);
    }

    public function test_a_voice_note_without_the_transcription_key_gets_a_clear_answer(): void
    {
        config(['whatsapp.transcribe.api_key' => null]);
        $this->app->instance(Transcriber::class, new \App\WhatsApp\HttpTranscriber);

        $this->voice();

        $this->assertSame([], $this->wa->downloads);
        $this->assertStringContainsString('OPENAI_API_KEY', $this->wa->lastReply());
        $this->assertStringContainsString('OPENAI_API_KEY', WhatsappMessage::sole()->error);
    }

    public function test_voice_notes_use_the_free_server_transcription_without_an_openai_key(): void
    {
        config(['whatsapp.transcribe.api_key' => null, 'whatsapp.transcribe.local_url' => 'http://whisper:9000']);
        $this->app->forgetInstance(Transcriber::class);
        $this->assertInstanceOf(\App\WhatsApp\LocalWhisperTranscriber::class, app(Transcriber::class));
        config(['whatsapp.transcribe.api_key' => 'sk-test']);
        $this->assertInstanceOf(\App\WhatsApp\HttpTranscriber::class, app(Transcriber::class));

        \Illuminate\Support\Facades\Http::fake(['whisper:9000/*' => \Illuminate\Support\Facades\Http::response(['text' => ' محمد رمضان فعلته سبع ايام ', 'language' => 'ar'])]);
        $text = (new \App\WhatsApp\LocalWhisperTranscriber)->transcribe('OGG-BYTES', 'audio/ogg; codecs=opus');

        $this->assertSame('محمد رمضان فعلته سبع ايام', $text);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://whisper:9000/asr?')
            && str_contains($r->url(), 'language=ar') && str_contains($r->url(), 'output=json'));
    }

    public function test_a_failed_transcription_gets_a_clear_answer(): void
    {
        $this->app->instance(Transcriber::class, new class implements Transcriber
        {
            public function transcribe(string $audio, string $mimeType): string
            {
                throw new \RuntimeException('connection refused');
            }
        });

        $this->voice();

        $this->assertStringContainsString('تعذّر تحويل الرسالة الصوتية', $this->wa->lastReply());
        $this->assertStringContainsString('connection refused', WhatsappMessage::sole()->error);
    }

    public function test_spoken_commands_as_speech_to_text_writes_them_are_understood(): void
    {
        $rules = new RuleInterpreter;
        $read = fn (string $t) => $rules->interpret($t)?->toArray();

        $this->assertSame(['intent' => 'activate', 'subscriber' => 'محمد رمضان', 'days' => 7], $read('محمد رمضان، فعلته سبعة أيام.'));
        $this->assertSame(['intent' => 'activate', 'subscriber' => 'محمد رمضان', 'days' => 14], $read('محمد رمضان جددته اسبوعين'));
        $this->assertSame(['intent' => 'payment', 'subscriber' => 'علي حسين', 'amount' => 35000], $read('علي حسين دفع خمسة وثلاثين ألف'));
        $this->assertSame(['intent' => 'payment', 'subscriber' => 'علي حسين', 'amount' => 150000], $read('علي حسين دفع مية وخمسين الف'));
        $this->assertSame(25000, $read('سجل دين على محمد رمضان خمسة وعشرين ألف')['amount']);
        $this->assertSame(250000, RuleInterpreter::wordsToNumber('ربع مليون'));
        $this->assertSame(35000, $read('علي حسين دفع 35,000.')['amount']);
        $this->assertNull(RuleInterpreter::wordsToNumber('محمد'));
    }

    public function test_a_spoken_activation_for_someone_runs(): void
    {
        $this->subscriber();
        $this->wa->transcript = 'فعلت لمحمد رمضان سبعة أيام.';

        $this->voice();

        $this->assertSame(1, Debt::count());
        $this->assertSame('done', WhatsappMessage::sole()->status);
    }

    public function test_a_voice_note_that_is_not_understood_shows_what_was_heard(): void
    {
        $this->wa->transcript = 'شلونكم اليوم';

        $this->voice();

        $this->assertStringContainsString('🎤 سمعت: «شلونكم اليوم»', $this->wa->lastReply());
    }

    public function test_transfer_purchase_and_sale_work_by_text_without_the_ai(): void
    {
        $account = $this->subscriber();
        $this->text('محمد رمضان فعلته سبع ايام');
        app(TreasuryService::class)->openingBalance($this->cash(), 500000);

        $this->text('ناقل دين محمد رمضان');
        $this->assertSame(DebtBucket::Primary, Debt::sole()->bucket);
        $this->assertStringContainsString('تمت المناقلة', $this->wa->lastReply());

        $this->text('شريت كيبل 30 متر سعر المتر 5 آلاف وراوتر بـ 40 الف');
        $this->assertSame([150000, 40000], Expense::orderBy('id')->pluck('amount')->all());

        $this->text('بعت 2 راوتر ب 80 الف');
        $sale = \App\Models\DeviceSale::sole();
        $this->assertSame([80000, 2, 'router'], [$sale->total_amount, $sale->quantity, $sale->itemType->key]);
        $this->assertSame(500000 - 190000 + 80000, app(TreasuryService::class)->balance($this->cash()));
        $this->assertSame([], $this->claude->calls, 'all understood without the AI');
    }

    public function test_a_misheard_name_still_finds_the_subscriber(): void
    {
        $this->subscriber('محمد رمضان جاسم');
        $this->subscriber('علي حسين كاظم');

        $this->text('محمد رمضن جاسم فعلته سبع ايام');

        $this->assertSame(1, Debt::count());
        $this->assertStringContainsString('محمد رمضان جاسم', $this->wa->lastReply());
    }

    public function test_the_speech_model_gets_the_command_words_and_subscriber_names_as_a_hint(): void
    {
        $this->subscriber();
        $this->text('محمد رمضان فعلته سبع ايام');
        config(['whatsapp.transcribe.local_url' => 'http://whisper:9000']);
        \Illuminate\Support\Facades\Http::fake(['whisper:9000/*' => \Illuminate\Support\Facades\Http::response(['text' => 'نص'])]);

        (new \App\WhatsApp\LocalWhisperTranscriber)->transcribe('OGG', 'audio/ogg');

        \Illuminate\Support\Facades\Http::assertSent(function ($r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_contains($q['initial_prompt'], 'فعلته سبع أيام') && str_contains($q['initial_prompt'], 'محمد رمضان') && $q['vad_filter'] === 'true';
        });
    }

    // ---- Queries ----

    public function test_queries_answer_shortly(): void
    {
        $this->subscriber();
        $this->text('محمد رمضان فعلته سبع ايام');

        $this->text('الديون الثانوية');
        $this->assertStringContainsString('محمد رمضان', $this->wa->lastReply());
        $this->assertStringContainsString('35,000', $this->wa->lastReply());

        $this->text('الديون الأولية');
        $this->assertStringContainsString('ما كو الديون الأولية', $this->wa->lastReply());

        $this->text('المفعلين اليوم');
        $this->assertStringContainsString('محمد رمضان', $this->wa->lastReply());

        $this->text('المتأخرين');
        $this->assertStringContainsString('ما كو متأخرين', $this->wa->lastReply());
        $this->travelTo(now()->addDays(9));
        $this->text('المتأخرين');
        $this->assertStringContainsString('محمد رمضان', $this->wa->lastReply());

        $this->text('مبيعات اليوم');
        $this->assertStringContainsString('مبيعات اليوم', $this->wa->lastReply());
        $this->text('مشتريات اليوم');
        $this->assertStringContainsString('مشتريات اليوم', $this->wa->lastReply());

        $ahmed = User::factory()->create(['name' => 'حيدر', 'branch_id' => $this->admin->branch_id]);
        app(EmployeeFinance::class)->giveAdvance($ahmed, 50000, 'سلفة', $this->cashWith(100000));
        $this->text('سلف الموظفين');
        $this->assertStringContainsString('حيدر', $this->wa->lastReply());
        $this->text('عهد الموظفين');
        $this->assertSame('done', WhatsappMessage::latest('id')->first()->status);

        $this->text('شكد دين محمد رمضان');
        $this->assertStringContainsString('35,000', $this->wa->lastReply());
    }

    public function test_an_employee_cannot_query_what_their_permissions_hide(): void
    {
        $this->employee('07702222222');

        $this->text('سلف الموظفين', from: '9647702222222');

        $this->assertSame('denied', WhatsappMessage::sole()->status);
    }

    // ---- Interpreters ----

    public function test_rules_read_iraqi_numbers_and_commands(): void
    {
        $this->assertSame(7, RuleInterpreter::days('سبع أيام'));
        $this->assertSame(7, RuleInterpreter::days('٧ ايام'));
        $this->assertSame(30, RuleInterpreter::days('شهر'));
        $this->assertSame(35000, RuleInterpreter::amount('35 الف'));
        $this->assertSame(35000, RuleInterpreter::amount('35,000 دينار'));
        $this->assertSame(250000, RuleInterpreter::amount('ربع مليون'));
        $this->assertNull(RuleInterpreter::amount('شوية'));

        $rules = new RuleInterpreter;
        $this->assertSame(['activate', 'محمد رمضان', 7], (fn ($c) => [$c->intent, $c->subscriber, $c->days])($rules->interpret('محمد رمضان فعلته سبعة أيام')));
        $this->assertSame(['payment', 'علي حسين', 25000], (fn ($c) => [$c->intent, $c->subscriber, $c->amount])($rules->interpret('علي حسين دفع 25 الف')));
        $this->assertSame('void_debt', $rules->interpret('امسح دين محمد')->intent);
        $this->assertSame('secondary_debts', $rules->interpret('الديون الثانوية')->query);
        $this->assertSame('custody', $rules->interpret('عهد الموظفين')->query);
        $this->assertSame('must_activate', $rules->interpret('يجب التفعيل')->query);
        $read = fn (string $t) => (fn ($c) => [$c->intent, $c->subscriber, $c->amount, $c->bucket])($rules->interpret($t));
        $this->assertSame(['add_debt', 'محمد رمضان', 25000, 'primary'], $read('سجل دين على محمد رمضان 25 الف'));
        $this->assertSame(['add_debt', 'علي حسين', 10000, 'secondary'], $read('سجل دين ثانوي على علي حسين 10 آلاف'));
        $this->assertSame(['add_debt', 'علي', 5000, 'primary'], $read('سجل دين على علي 5000'));
        $this->assertSame(['add_debt', 'علي حسين', 20000, 'primary'], $read('علي حسين عليه 20 الف دين'));
        $this->assertSame(['query', 'محمد', null, null], $read('شكد دين على محمد'));
        $this->assertSame('purchase', $rules->interpret('شريت كيبل 30 متر سعر المتر 5 آلاف')->intent, 'purchases no longer need the AI');
        $this->assertNull($rules->interpret('شلون الشغل اليوم'), 'free text goes to the AI');
    }

    public function test_claude_request_uses_a_json_schema_and_server_side_fallbacks(): void
    {
        config(['whatsapp.ai.model' => 'claude-opus-5-5']);
        $request = (new ClaudeInterpreter)->request('شريت كيبل', ['text' => 'فعلت محمد', 'question' => 'كم يوم؟']);

        $this->assertSame('claude-opus-5-5', $request['model']);
        $this->assertSame('json_schema', $request['outputConfig']['format']['type']);
        $this->assertSame(['intent', 'subscriber', 'days', 'amount', 'items', 'query', 'question', 'bucket', 'on_credit'], $request['outputConfig']['format']['schema']['required']);
        $this->assertSame('default', $request['fallbacks']);
        $this->assertSame(['server-side-fallback-2026-07-01'], $request['betas']);
        $this->assertStringContainsString('كم يوم؟', $request['messages'][0]['content']);

        $command = (new ClaudeInterpreter)->parse('{"intent":"purchase","subscriber":null,"days":null,"amount":null,"items":[{"description":"كيبل 30 متر","quantity":30,"unit_price":5000,"total":150000}],"query":null,"question":null}');
        $this->assertSame('purchase', $command->intent);
        $this->assertSame(150000, $command->items[0]['total']);
        $this->assertNull((new ClaudeInterpreter)->parse('not json'));
    }

    public function test_without_the_ai_key_free_text_gets_the_help_message(): void
    {
        $this->text('مرحبا');

        $this->assertStringContainsString('أمثلة', $this->wa->lastReply());
    }

    // ---- Numbers and screens ----

    public function test_number_management_is_audited_and_normalized(): void
    {
        $registry = app(NumberRegistry::class);
        $number = $registry->add('+964 770 333 4444', $this->admin, 'المحل');
        $this->assertSame('9647703334444', $number->phone);

        try {
            $registry->add('07703334444', $this->admin);
            $this->fail('duplicate number accepted');
        } catch (\App\Exceptions\BusinessRuleException) {
        }

        $registry->setActive($number, false);
        $registry->delete($number);

        $this->assertSame(
            ['whatsapp_number.added', 'whatsapp_number.added', 'whatsapp_number.disabled', 'whatsapp_number.deleted'],
            AuditLog::where('action', 'like', 'whatsapp_number.%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_whatsapp_screens_open_for_the_admin_only(): void
    {
        $this->text('مرحبا');

        Livewire::test(ListWhatsappMessages::class)->assertOk()->assertSee('مرحبا');
        Livewire::test(ManageWhatsappNumbers::class)->assertOk()->assertSee('9647701111111');
        Livewire::test(WhatsappSettings::class)->assertOk()->assertSee('ربط الهاتف بالباركود')->assertSee('خدمة الربط لا تعمل')
            ->fillForm(['enabled' => false, 'voice_enabled' => false, 'unauthorized_message' => 'غير مسموح', 'duplicate_hours' => 6])
            ->call('save');
        $this->assertFalse((bool) app(Settings::class)->get('whatsapp.enabled'));
        $this->assertSame('غير مسموح', app(Settings::class)->get('whatsapp.unauthorized_message'));

        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->assignRole('employee');
        $this->actingAs($employee);
        $this->get(ListWhatsappMessages::getUrl())->assertForbidden();
        $this->get(ManageWhatsappNumbers::getUrl())->assertForbidden();
    }

    private function cashWith(int $amount): \App\Models\MoneyAccount
    {
        app(TreasuryService::class)->openingBalance($this->cash(), $amount);

        return $this->cash();
    }
}
