<?php

namespace App\Filament\Pages;

use App\Services\Audit;
use App\Services\Settings as SettingsService;
use App\WhatsApp\ClaudeInterpreter;
use App\WhatsApp\CloudApiGateway;
use App\WhatsApp\HttpTranscriber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Turning the WhatsApp control panel on and off, its messages, and the state of the server keys
 * (shown as set / not set only; the keys themselves live in the server's .env).
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
        'enabled' => 'whatsapp.enabled',
        'voice_enabled' => 'whatsapp.voice_enabled',
        'unauthorized_message' => 'whatsapp.unauthorized_message',
        'duplicate_hours' => 'whatsapp.duplicate_hours',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()->can('whatsapp.manage') && auth()->user()->can('settings.manage');
    }

    public function mount(): void
    {
        $settings = app(SettingsService::class);
        $this->form->fill(array_map(fn (string $key) => $settings->get($key), self::KEYS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Form::make([
                Section::make('التشغيل')->columns(2)->schema([
                    Toggle::make('enabled')->label('تشغيل لوحة تحكم واتساب')
                        ->helperText('عند الإيقاف تُسجَّل الرسائل الواردة ولا يُنفَّذ منها شيء.'),
                    Toggle::make('voice_enabled')->label('قبول الرسائل الصوتية'),
                    TextInput::make('duplicate_hours')->label('منع تكرار تفعيل نفس المشترك خلال (ساعة)')->integer()->minValue(1)->maxValue(168)->required(),
                ]),
                Section::make('الرسائل')->schema([
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
        $new = [
            'enabled' => (bool) $data['enabled'],
            'voice_enabled' => (bool) $data['voice_enabled'],
            'unauthorized_message' => trim($data['unauthorized_message']),
            'duplicate_hours' => (int) $data['duplicate_hours'],
        ];
        foreach (self::KEYS as $field => $key) {
            $settings->set($key, $new[$field]);
        }
        app(Audit::class)->log('settings.updated', 'whatsapp', $old, $new);
        Notification::make()->success()->title('تم الحفظ')->send();
    }

    protected function getViewData(): array
    {
        return [
            'webhookUrl' => route('whatsapp.webhook'),
            'checks' => [
                ['WHATSAPP_ACCESS_TOKEN + WHATSAPP_PHONE_NUMBER_ID', 'إرسال الردود (WhatsApp Cloud API)', CloudApiGateway::configured()],
                ['WHATSAPP_APP_SECRET', 'التحقق من توقيع الرسائل الواردة', filled(config('whatsapp.app_secret'))],
                ['WHATSAPP_VERIFY_TOKEN', 'ربط الـ Webhook في Meta', filled(config('whatsapp.verify_token'))],
                ['ANTHROPIC_API_KEY', 'فهم الأوامر الحرة والمشتريات (Claude)', ClaudeInterpreter::configured()],
                ['OPENAI_API_KEY', 'تحويل الرسائل الصوتية إلى نص', HttpTranscriber::configured()],
            ],
        ];
    }
}
