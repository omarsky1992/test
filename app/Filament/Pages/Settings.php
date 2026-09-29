<?php

namespace App\Filament\Pages;

use App\Services\Audit;
use App\Services\Settings as SettingsService;
use BackedEnum;
use Filament\Actions\Action;
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
    ];

    public static function canAccess(): bool
    {
        return auth()->user()->can('settings.manage');
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
            $settings->set($key, in_array($field, ['partial_days', 'full_days', 'discount_rounding'], true) ? (int) $data[$field] : $data[$field]);
        }
        app(Audit::class)->log('settings.updated', 'settings', $old, $data);
        Notification::make()->success()->title('تم الحفظ')->send();
    }
}
