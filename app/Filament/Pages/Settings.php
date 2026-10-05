<?php

namespace App\Filament\Pages;

use App\Services\Audit;
use App\Services\Settings as SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'الإعدادات';

    /** @var array<string, mixed> */
    public ?array $data = [];

    private const KEYS = [
        'company_name' => 'company.name',
        'company_address' => 'company.address',
        'company_phone' => 'company.phone',
        'partial_days' => 'activation.partial_days',
        'full_days' => 'activation.full_days',
        'discount_rounding' => 'pricing.discount_rounding',
        'expiring_days' => 'subscribers.expiring_days',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()->can('settings.manage');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetSystem')
                ->label('تصفير النظام')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->visible(fn () => auth()->user()->isAdmin())
                ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                ->modalIconColor('danger')
                ->modalHeading('تصفير النظام')
                ->modalDescription('تحذير: تُحذف نهائياً كل الحركات المالية والتجريبية: السندات، الديون، التفعيلات، المناقلات، المصروفات، مبيعات الأجهزة، الراجع، عهد الموظفين، السلف وتسديداتها، والتجديدات. تبقى: حسابات المستخدمين والمدير، الإعدادات، الفئات، العروض، طرق الدفع، الصناديق (بأرصدة صفر) وسجل العمليات. لا يمكن التراجع، فخذ نسخة احتياطية أولاً.')
                ->modalSubmitActionLabel('تصفير نهائي')
                ->schema([
                    Checkbox::make('with_subscribers')->label('حذف المشتركين واليوزرات أيضاً (لإعادة استيرادهم من Excel)')->default(true),
                    TextInput::make('password')->label('كلمة مرور المدير')->password()->required()->autocomplete('current-password'),
                    TextInput::make('confirm')->label('للتأكيد النهائي اكتب: تصفير')->required()
                        ->rules([fn () => fn (string $attribute, $value, \Closure $fail) => trim((string) $value) === 'تصفير' ? null : $fail('اكتب كلمة «تصفير» كما هي.')]),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        $counts = app(\App\Services\SystemReset::class)->run(auth()->user(), $data['password'], (bool) ($data['with_subscribers'] ?? false));
                    } catch (\App\Exceptions\BusinessRuleException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }
                    Notification::make()->success()->title('تم تصفير النظام')
                        ->body('حُذف '.number_format(array_sum($counts)).' سجل. يمكنك الآن استيراد المشتركين من Excel.')->persistent()->send();
                }),
        ];
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
                Section::make('بيانات الجهة (تظهر في السند)')->columns(3)->schema([
                    TextInput::make('company_name')->label('اسم الوكيل')->required(),
                    TextInput::make('company_address')->label('العنوان'),
                    TextInput::make('company_phone')->label('الهاتف'),
                ]),
                Section::make('التفعيل')->columns(3)->schema([
                    TextInput::make('partial_days')->label('مدة التفعيل القصير (يوم)')->integer()->minValue(1)->required(),
                    TextInput::make('full_days')->label('مدة التفعيل الكامل (يوم)')->integer()->minValue(1)->required()->gt('partial_days'),
                    TextInput::make('discount_rounding')->label('تقريب خصم النسبة (د.ع)')->integer()->minValue(1)->required(),
                ]),
                Section::make('المشتركون')->columns(3)->schema([
                    TextInput::make('expiring_days')->label('«ينتهي قريباً»: خلال كم يوم')->integer()->minValue(1)->maxValue(60)->required()
                        ->helperText('يُستخدم في الرئيسية وبطاقات المشتركين وتذكير واتساب.'),
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
        foreach (self::KEYS as $field => $key) {
            $settings->set($key, in_array($field, ['partial_days', 'full_days', 'discount_rounding', 'expiring_days'], true) ? (int) $data[$field] : $data[$field]);
        }
        app(Audit::class)->log('settings.updated', 'settings', $old, $data);
        Notification::make()->success()->title('تم الحفظ')->send();
    }
}
