<?php

namespace App\Filament\Pages;

use App\Models\AccountRenewal;
use App\Models\SyncRun;
use App\Services\Audit;
use App\Services\Settings;
use App\Sync\CompanySync;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;
use UnitEnum;

/**
 * Settings, manual run, preview and history of the sync with the company site.
 */
class CompanySyncPage extends Page
{
    protected string $view = 'filament.pages.company-sync';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'مزامنة موقع الشركة';

    protected static ?string $slug = 'company-sync';

    /** @var array<int, array<string, ?string>>|null */
    public ?array $previewRows = null;

    public static function canAccess(): bool
    {
        return auth()->user()->can('sync.run') || auth()->user()->can('settings.manage');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')
                ->label('مزامنة مباشرة من السيرفر')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->authorize('sync.run')
                ->requiresConfirmation()
                ->modalDescription('يجلب النظام صفحة المشتركين من موقع الشركة ويحدّث البيانات الحالية. لا يُحذف شيء ولا يُعدَّل السجل المالي. التجديد (0 يوم ← أكثر من 0) يُنشئ ديناً ثانوياً مرة واحدة.')
                ->action(function () {
                    @set_time_limit(600);
                    $run = app(CompanySync::class)->run('manual');
                    $s = $run->stats ?? [];
                    $run->status === 'success'
                        ? Notification::make()->success()->title('تمت المزامنة')
                            ->body("جديد: {$s['subscribers_created']} مشترك و{$s['accounts_created']} حساب · محدَّث: {$s['accounts_updated']} · تجديدات: {$s['renewals']} · أخطاء: ".count($s['errors'] ?? []))
                            ->send()
                        : Notification::make()->danger()->title('فشلت المزامنة')->body($run->error)->persistent()->send();
                }),
            Action::make('preview')
                ->label('معاينة بدون حفظ')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->authorize('sync.run')
                ->action(function () {
                    try {
                        $this->previewRows = app(CompanySync::class)->preview(10);
                        Notification::make()->success()->title('الاتصال يعمل')->body('تظهر أول '.count($this->previewRows).' سجلات كما سيقرؤها النظام. لم يُحفظ شيء.')->send();
                    } catch (Throwable $e) {
                        $this->previewRows = null;
                        Notification::make()->danger()->title('تعذّر جلب البيانات')->body($e->getMessage())->persistent()->send();
                    }
                }),
            Action::make('settings')
                ->label('إعدادات الاتصال المباشر')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->authorize('settings.manage')
                ->fillForm(fn () => $this->settingsData())
                ->schema([
                    Section::make('المزامنة التلقائية')->columns(2)->schema([
                        Toggle::make('enabled')->label('تشغيل المزامنة التلقائية'),
                        TextInput::make('interval_minutes')->label('كل (دقيقة)')->integer()->minValue(15)->maxValue(1440)->required(),
                    ]),
                    Section::make('حساب موقع الشركة')
                        ->description('يُحفظ مشفّراً. اترك الباسورد فارغاً للإبقاء على المحفوظ. يُفضَّل يوزر خاص للنظام بصلاحية عرض فقط.')
                        ->columns(2)->schema([
                            TextInput::make('username')->label('اليوزر')->autocomplete('off'),
                            TextInput::make('password')->label('الباسورد')->password()->revealable()->autocomplete('new-password')
                                ->placeholder(fn () => filled(app(Settings::class)->get('sync.password')) ? '•••••• (محفوظ)' : null),
                            TextInput::make('client_id')->label('معرّف العميل (client_id)')->helperText('القيمة الافتراضية earthlink-portals صحيحة لموقع admin.ftth.iq.')->required(),
                            TextInput::make('refresh_token')->label('مفتاح التجديد (اختياري)')->password()->autocomplete('off')
                                ->helperText('إذا رفض EarthLink الدخول باليوزر والباسورد: بموقع الشركة اضغط F12 ← Console واكتب copy(localStorage.refresh_token) ثم الصقه هنا. يجدده النظام تلقائياً كل 10 دقائق.'),
                        ]),
                    Section::make('متقدم')->collapsed()->columns(2)->schema([
                        TextInput::make('base_url')->label('عنوان الموقع')->url()->required(),
                        TextInput::make('token_url')->label('عنوان تسجيل الدخول')->url()->required(),
                        TextInput::make('client_app')->label('X-Client-App')->required(),
                        TextInput::make('hierarchy_level')->label('hierarchyLevel'),
                        TextInput::make('detail_limit')->label('أقصى تفاصيل مشتركين تُجلب في كل مزامنة')->integer()->minValue(0)->required(),
                    ]),
                ])
                ->action(fn (array $data) => $this->saveSettings($data)),
        ];
    }

    private function settingsData(): array
    {
        $s = app(Settings::class);

        return [
            'enabled' => (bool) $s->get('sync.enabled'),
            'interval_minutes' => (int) $s->get('sync.interval_minutes'),
            'username' => $s->get('sync.username'),
            'client_id' => $s->get('sync.client_id'),
            'base_url' => $s->get('sync.base_url'),
            'token_url' => $s->get('sync.token_url'),
            'client_app' => $s->get('sync.client_app'),
            'hierarchy_level' => $s->get('sync.hierarchy_level'),
            'detail_limit' => (int) $s->get('sync.detail_limit'),
        ];
    }

    private function saveSettings(array $data): void
    {
        $s = app(Settings::class);
        $old = $this->settingsData();
        foreach (['username', 'client_id', 'base_url', 'token_url', 'client_app', 'hierarchy_level'] as $key) {
            $s->set("sync.{$key}", filled($data[$key] ?? null) ? trim($data[$key]) : null);
        }
        $s->set('sync.enabled', (bool) $data['enabled']);
        $s->set('sync.interval_minutes', (int) $data['interval_minutes']);
        $s->set('sync.detail_limit', (int) $data['detail_limit']);
        if (filled($data['password'] ?? null)) {
            $s->set('sync.password', Crypt::encryptString($data['password']));
        }
        if (filled($data['refresh_token'] ?? null)) {
            $s->set('sync.refresh_token', Crypt::encryptString(trim($data['refresh_token'])));
        }
        Cache::forget('sync.company.access_token');

        app(Audit::class)->log('settings.sync_updated', 'settings', $old, [
            ...$this->settingsData(), 'password_changed' => filled($data['password'] ?? null), 'refresh_token_changed' => filled($data['refresh_token'] ?? null),
        ]);
        Notification::make()->success()->title('تم حفظ إعدادات المزامنة')->send();
    }

    protected function getViewData(): array
    {
        $s = app(Settings::class);

        return [
            'enabled' => (bool) $s->get('sync.enabled'),
            'interval' => (int) $s->get('sync.interval_minutes'),
            'configured' => filled($s->get('sync.client_id')) && (filled($s->get('sync.password')) || filled($s->get('sync.refresh_token'))),
            'hasRefreshToken' => filled($s->get('sync.refresh_token')),
            'bookmarklet' => \App\Sync\Bookmarklet::href(request()->getSchemeAndHttpHost(), (string) $s->get('sync.client_app')),
            'lastBrowser' => SyncRun::where('trigger', 'browser')->where('status', 'success')->latest('started_at')->first(),
            'refreshedAt' => filled($s->get('sync.refreshed_at')) ? \Carbon\CarbonImmutable::parse($s->get('sync.refreshed_at')) : null,
            'last' => SyncRun::where('status', 'success')->latest('started_at')->first(),
            'runs' => SyncRun::with('creator')->latest('started_at')->limit(20)->get(),
            'renewals' => AccountRenewal::with(['account', 'subscriber', 'debt'])->latest('detected_at')->limit(30)->get(),
        ];
    }
}
