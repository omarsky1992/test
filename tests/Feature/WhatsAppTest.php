<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Subscriber;
use App\Models\User;
use App\Models\WhatsAppAuthorizedUser;
use App\Support\Arabic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_id' => '123456789',
            'services.whatsapp.verify_token' => 'verify-token',
            'services.whatsapp.app_secret' => null,
        ]);

        Http::fake();
    }

    private function fakeWhatsAppApi(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid-1']]], 200);
            }

            return Http::response('unexpected '.$request->url(), 500);
        });
    }

    private function authorizedPhone(): string
    {
        return '9647701234567';
    }

    private function createAuthorizedUser(string $phone = '07701234567'): WhatsAppAuthorizedUser
    {
        $branch = Branch::firstOrCreate(['name' => 'الفرع الرئيسي'], ['is_active' => true]);
        $user = User::first();

        return WhatsAppAuthorizedUser::create([
            'phone_normalized' => Arabic::phone($phone),
            'name' => 'مدير',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    private function webhookPayload(string $from, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'messages' => [[
                            'from' => $from,
                            'id' => 'wamid.incoming',
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    public function test_webhook_verification_accepts_the_correct_token(): void
    {
        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=verify-token&hub_challenge=challenge-123')
            ->assertOk()
            ->assertSee('challenge-123');
    }

    public function test_webhook_verification_rejects_a_wrong_token(): void
    {
        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=challenge-123')
            ->assertForbidden();
    }

    public function test_unauthorized_phone_is_refused(): void
    {
        $this->fakeWhatsAppApi();

        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload('9647999999999', 'تسجيل'))
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->body(), 'غير مصرح'));
    }

    public function test_help_command_returns_available_commands(): void
    {
        $this->fakeWhatsAppApi();
        $this->createAuthorizedUser();

        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'مساعدة'))
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->body(), 'تسجيل')
            && str_contains($r->body(), 'حساب'));
    }

    public function test_registering_a_subscriber_via_whatsapp(): void
    {
        $this->fakeWhatsAppApi();
        $this->createAuthorizedUser();

        // 1. أمر تسجيل
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'تسجيل'))->assertOk();

        // 2. الاسم
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'علي محمد'))->assertOk();

        // 3. الهاتف
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), '07701234567'))->assertOk();

        // 4. العنوان
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'بغداد - الكرادة'))->assertOk();

        // التحقق من إنشاء المشترك
        $subscriber = Subscriber::where('full_name', 'علي محمد')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame('9647701234567', $subscriber->phone_normalized);
        $this->assertStringStartsWith('C-', $subscriber->code);

        // التحقق من إرسال رسالة النجاح
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->body(), 'تم تسجيل المشترك بنجاح'));
    }

    public function test_adding_an_account_via_whatsapp(): void
    {
        $this->fakeWhatsAppApi();
        $authorized = $this->createAuthorizedUser();

        // إنشاء مشترك مسبقاً
        $branch = Branch::first();
        $subscriber = Subscriber::create([
            'branch_id' => $branch->id,
            'code' => 'C-000001',
            'full_name' => 'حسن علي',
            'name_search' => 'حسن علي',
            'phone' => '07709999999',
            'phone_normalized' => '9647709999999',
            'status' => 'active',
            'created_by' => $authorized->user_id,
        ]);

        // 1. أمر حساب
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'حساب'))->assertOk();

        // 2. كود المشترك
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'C-000001'))->assertOk();

        // 3. اليوزر
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'user1'))->assertOk();

        // 4. الباسورد
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'secret123'))->assertOk();

        // 5. السيريال
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'SN-12345'))->assertOk();

        // التحقق من إنشاء الحساب
        $account = $subscriber->accounts()->where('username', 'user1')->first();
        $this->assertNotNull($account);
        $this->assertSame('SN-12345', $account->serial_number);

        // التحقق من إرسال رسالة النجاح
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->body(), 'تم إضافة الحساب بنجاح'));
    }

    public function test_status_query_returns_subscriber_info(): void
    {
        $this->fakeWhatsAppApi();
        $authorized = $this->createAuthorizedUser();

        $branch = Branch::first();
        $subscriber = Subscriber::create([
            'branch_id' => $branch->id,
            'code' => 'C-000002',
            'full_name' => 'سعد كريم',
            'name_search' => 'سعد كريم',
            'phone' => '07708888888',
            'phone_normalized' => '9647708888888',
            'status' => 'active',
            'created_by' => $authorized->user_id,
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'حالة'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'C-000002'))->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->body(), 'سعد كريم')
            && str_contains($r->body(), 'C-000002'));
    }

    public function test_audio_message_is_transcribed_and_processed(): void
    {
        $this->fakeWhatsAppApi();
        $this->createAuthorizedUser();

        // محاكاة رسالة صوتية
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'messages' => [[
                            'from' => $this->authorizedPhone(),
                            'id' => 'wamid.audio',
                            'timestamp' => (string) time(),
                            'type' => 'audio',
                            'audio' => ['id' => 'media-id-1', 'mime_type' => 'audio/ogg'],
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        // محاكاة: تحميل الوسائط + تحويل الصوت لنص + إرسال الرد
        Http::fake(function (Request $request) {
            $url = $request->url();

            // الحصول على رابط تحميل الوسائط
            if (str_contains($url, 'graph.facebook.com') && str_contains($url, '/media/media-id-1')) {
                return Http::response(['url' => 'https://lookaside.fbsbx.com/audio/audio.ogg'], 200);
            }

            // تحميل ملف الصوت
            if (str_contains($url, 'lookaside.fbsbx.com')) {
                return Http::response('fake-audio-binary', 200);
            }

            // تحويل الصوت لنص (Whisper)
            if (str_contains($url, 'api.openai.com/v1/audio/transcriptions')) {
                return Http::response(['text' => 'تسجيل'], 200);
            }

            // إرسال رسالة واتساب
            if (str_contains($url, 'graph.facebook.com') && str_contains($url, '/messages')) {
                return Http::response(['messages' => [['id' => 'wamid-1']]], 200);
            }

            return Http::response('unexpected '.$url, 500);
        });

        config(['services.whatsapp.openai_key' => 'openai-key']);

        $this->postJson('/api/whatsapp/webhook', $payload)->assertOk();

        // التحقق من أن النظام بدأ حوار التسجيل (أرسل رسالة تطلب الاسم)
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->url(), '/messages')
            && str_contains($r->body(), 'اسم المشترك'));
    }

    public function test_activation_30_days_records_a_primary_debt(): void
    {
        $this->fakeWhatsAppApi();
        $authorized = $this->createAuthorizedUser();

        // إنشاء مشترك مع حساب
        $branch = Branch::first();
        $subscriber = app(\App\Services\SubscriberService::class)->create(
            ['branch_id' => $branch->id, 'full_name' => 'محمد علي', 'phone' => '07701111111'],
            ['username' => 'mohammed', 'secret' => 'pass123', 'created_by' => $authorized->user_id],
        );

        // إرسال أمر التفعيل
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'تفعيل محمد علي 30 يوم'))
            ->assertOk();

        // التحقق من إنشاء التفعيل والدين الأولي
        $activation = \App\Models\Activation::where('subscriber_id', $subscriber->id)->first();
        $this->assertNotNull($activation);
        $this->assertSame('full_30', $activation->kind->value);
        $this->assertSame('primary', $activation->debt->bucket->value);

        // التحقق من إرسال رسالة النجاح
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->url(), '/messages')
            && str_contains($r->body(), 'تم تفعيل الاشتراك'));
    }

    public function test_activation_7_days_records_a_secondary_debt(): void
    {
        $this->fakeWhatsAppApi();
        $authorized = $this->createAuthorizedUser();

        // إنشاء مشترك مع حساب
        $branch = Branch::first();
        $subscriber = app(\App\Services\SubscriberService::class)->create(
            ['branch_id' => $branch->id, 'full_name' => 'حسين عباس', 'phone' => '07702222222'],
            ['username' => 'hussein', 'secret' => 'pass123', 'created_by' => $authorized->user_id],
        );

        // إرسال أمر التفعيل
        $this->postJson('/api/whatsapp/webhook', $this->webhookPayload($this->authorizedPhone(), 'تفعيل حسين عباس 7 أيام'))
            ->assertOk();

        // التحقق من إنشاء التفعيل والدين الثانوي
        $activation = \App\Models\Activation::where('subscriber_id', $subscriber->id)->first();
        $this->assertNotNull($activation);
        $this->assertSame('partial_7', $activation->kind->value);
        $this->assertSame('secondary', $activation->debt->bucket->value);

        // التحقق من إرسال رسالة النجاح
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->url(), '/messages')
            && str_contains($r->body(), 'تم تفعيل الاشتراك'));
    }
}
