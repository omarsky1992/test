<?php

namespace App\Filament\Pages;

use App\Services\Audit;
use App\Services\Settings as SettingsService;
use App\WhatsApp\ClaudeInterpreter;
use App\WhatsApp\CloudApiGateway;
use App\WhatsApp\HttpTranscriber;
use App\WhatsApp\WahaGateway;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Linking the phone by QR code, the staff alerts, the automatic messages to subscribers (each one
 * switched on or off), and the WhatsApp commands.
 */
class WhatsappSettings extends Page
{
    protected string $view = 'filament.pages.whatsapp-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'إعدادات واتساب';

    protected static ?string $slug = 'whatsapp-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    private const KEYS = [
        'driver' => 'whatsapp.driver',
        'enabled' => 'whatsapp.enabled',
        'voice_enabled' => 'whatsapp.voice_enabled',
        'unauthorized_message' => 'whatsapp.unauthorized_message',
        'duplicate_hours' => 'whatsapp.duplicate_hours',
        'alert_must_activate' => 'alerts.must_activate',
        'alert_secondary_expiring' => 'alerts.secondary_expiring',
        'alert_hours_before' => 'alerts.hours_before',
        'sub_enabled' => 'subscriber_messages.enabled',
        'sub_renewal' => 'subscriber_messages.renewal',
        'sub_expiring' => 'subscriber_messages.expiring',
        'sub_expired' => 'subscriber_messages.expired',
        'sub_debt' => 'subscriber_messages.debt',
        'sub_expiring_hours' => 'subscriber_messages.expiring_hours',
        'sub_debt_every_days' => 'subscriber_messages.debt_every_days',
        'send_from_hour' => 'whatsapp.send_from_hour',
        'send_until_hour' => 'whatsapp.send_until_hour',
        'batch_size' => 'whatsapp.batch_size',
        'daily_limit' => 'whatsapp.daily_limit',
    ];

    private const INTEGERS = ['duplicate_hours', 'alert_hours_before', 'sub_expiring_hours', 'sub_debt_every_days', 'send_from_hour', 'send_until_hour', 'batch_size', 'daily_limit'];

    private const BOOLEANS = ['enabled', 'voice_enabled', 'alert_must_activate', 'alert_secondary_expiring', 'sub_enabled', 'sub_renewal', 'sub_expiring', 'sub_expired', 'sub_debt'];

    public static function canAccess(): bool
    {
        return auth()->user()->can('whatsapp.manage') && auth()->user()->can('settings.manage');
    }

    public function mount(): void
    {
        $settings = app(SettingsService::class);
        $this->form->fill(array_map(fn (string $key) => $settings->get($key), self::KEYS));
    }

    // ---- Linking the phone ----

    public function linkPhone(): void
    {
        $this->qrAction(fn (WahaGateway $waha) => $waha->start(), 'whatsapp.qr_started', 'امسح الباركود من هاتفك: واتساب ← الأجهزة المرتبطة ← ربط جهاز.');
    }

    public function restartPhone(): void
    {
        $this->qrAction(fn (WahaGateway $waha) => $waha->restart(), 'whatsapp.qr_restarted', 'أُعيد تشغيل الاتصال.');
    }

    public function unlinkPhone(): void
    {
        $this->qrAction(fn (WahaGateway $waha) => $waha->logout(), 'whatsapp.qr_unlinked', 'فُصل الهاتف. اربط جهازاً جديداً بالباركود.');
    }

    private function qrAction(callable $callback, string $audit, string $done): void
    {
        try {
            $callback(app(WahaGateway::class));
            app(Audit::class)->log($audit, 'whatsapp');
            Notification::make()->success()->title($done)->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('تعذّر الاتصال بخدمة الربط')->body(mb_strimwidth($e->getMessage(), 0, 300, '…'))->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        $hour = fn (string $name, string $label) => TextInput::make($name)->label($label)->integer()->minValue(0)->maxValue(24)->required();

        return $schema->statePath('data')->components([
            Form::make([
                Section::make('طريقة الربط')->schema([
                    ToggleButtons::make('driver')->label('الإرسال والاستقبال عبر')->inline()->required()
                        ->options(['qr' => 'هاتف مربوط بالباركود', 'meta' => 'WhatsApp Cloud API (Meta)']),
                ]),
                Section::make('تنبيهات الموظفين')
                    ->description('تُرسل إلى الأرقام المعلَّم عليها «يستلم التنبيهات» في «الأرقام المصرح لها».')
                    ->columns(3)->schema([
                        Toggle::make('alert_must_activate')->label('يجب التفعيل (عند تسديد الدين الثانوي)'),
                        Toggle::make('alert_secondary_expiring')->label('دين ثانوي ينتهي اشتراكه قريباً'),
                        TextInput::make('alert_hours_before')->label('قبل الانتهاء بـ (ساعة)')->integer()->minValue(1)->maxValue(168)->required(),
                    ]),
                Section::make('رسائل المشتركين التلقائية')
                    ->description('نص كل رسالة من «قوالب الرسائل». لا تُرسل إلا للمشترك الذي له رقم هاتف، ومرة واحدة لكل مناسبة.')
                    ->columns(3)->schema([
                        Toggle::make('sub_enabled')->label('تشغيل رسائل المشتركين')->live()->columnSpanFull(),
                        Toggle::make('sub_renewal')->label('عند التجديد')->disabled(fn (Get $get) => ! $get('sub_enabled')),
                        Toggle::make('sub_expiring')->label('قرب انتهاء الاشتراك')->disabled(fn (Get $get) => ! $get('sub_enabled')),
                        Toggle::make('sub_expired')->label('عند انتهاء الاشتراك')->disabled(fn (Get $get) => ! $get('sub_enabled')),
                        Toggle::make('sub_debt')->label('تذكير بالديون')->disabled(fn (Get $get) => ! $get('sub_enabled')),
                        TextInput::make('sub_expiring_hours')->label('«قرب الانتهاء» قبل (ساعة)')->integer()->minValue(1)->maxValue(168)->required(),
                        TextInput::make('sub_debt_every_days')->label('تذكير الدين كل (يوم)')->integer()->minValue(1)->maxValue(30)->required(),
                    ]),
                Section::make('حماية الرقم من الحظر')
                    ->description('تُرسل الرسائل ببطء من الهاتف المربوط. رسائل المشتركين في النهار فقط؛ تنبيهات الموظفين في أي وقت.')
                    ->columns(4)->schema([
                        $hour('send_from_hour', 'الإرسال للمشتركين من الساعة'),
                        $hour('send_until_hour', 'حتى الساعة'),
                        TextInput::make('batch_size')->label('رسائل في الدقيقة')->integer()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('daily_limit')->label('حد يومي')->integer()->minValue(1)->maxValue(2000)->required(),
                    ]),
                Section::make('أوامر واتساب')->columns(2)->schema([
                    Toggle::make('enabled')->label('تنفيذ الأوامر من الأرقام المصرح لها')
                        ->helperText('عند الإيقاف تُسجَّل الرسائل الواردة ولا يُنفَّذ منها شيء.'),
                    Toggle::make('voice_enabled')->label('قبول الرسائل الصوتية'),
                    TextInput::make('duplicate_hours')->label('منع تكرار تفعيل نفس المشترك خلال (ساعة)')->integer()->minValue(1)->maxValue(168)->required(),
                    Textarea::make('unauthorized_message')->label('الرد على رقم غير مصرح له')->required()->rows(2)->maxLength(300),
                ]),
            ])->livewireSubmitHandler('save')->footer([
                Actions::make([Action::make('save')->label('حفظ')->submit('save')]),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(SettingsService::class);
        $old = array_map(fn (string $key) => $settings->get($key), self::KEYS);
        $new = [];
        foreach (self::KEYS as $field => $key) {
            $value = $data[$field] ?? $old[$field];
            $new[$field] = match (true) {
                in_array($field, self::INTEGERS, true) => (int) $value,
                in_array($field, self::BOOLEANS, true) => (bool) $value,
                default => is_string($value) ? trim($value) : $value,
            };
            $settings->set($key, $new[$field]);
        }
        app(Audit::class)->log('settings.updated', 'whatsapp', $old, $new);
        Notification::make()->success()->title('تم الحفظ')->send();
    }

    protected function getViewData(): array
    {
        $qr = app(SettingsService::class)->get('whatsapp.driver') !== 'meta';

        return [
            'qrMode' => $qr,
            'link' => $qr ? app(WahaGateway::class)->status() : null,
            'statusLabels' => WahaGateway::STATUS_LABELS,
            'webhookUrl' => route('whatsapp.webhook'),
            'outbox' => [
                'pending' => \App\Models\WhatsappOutbox::where('status', 'pending')->count(),
                'sent_today' => \App\Models\WhatsappOutbox::where('status', 'sent')->where('sent_at', '>=', now()->startOfDay())->count(),
                'failed' => \App\Models\WhatsappOutbox::where('status', 'failed')->count(),
            ],
            'checks' => [
                ['WAHA_API_KEY + WAHA_WEBHOOK_SECRET', 'خدمة الربط بالباركود', WahaGateway::configured() && filled(config('whatsapp.waha.webhook_secret'))],
                ['ANTHROPIC_API_KEY', 'فهم الأوامر الحرة والمشتريات (Claude)', ClaudeInterpreter::configured()],
                ['OPENAI_API_KEY', 'تحويل الرسائل الصوتية إلى نص', HttpTranscriber::configured()],
                ['WHATSAPP_ACCESS_TOKEN + WHATSAPP_PHONE_NUMBER_ID', 'Meta Cloud API (إذا اخترتها)', CloudApiGateway::configured()],
            ],
        ];
    }
}
