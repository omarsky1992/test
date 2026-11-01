<?php

namespace App\Filament\Platform\Pages;

use App\Platform\PlatformAudit;
use App\Platform\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** How clients reach the owner to renew: shown on their alerts and on the «انتهى الاشتراك» page. */
class PlatformSettingsPage extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static ?string $slug = 'settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $title = 'إعدادات المنصة';

    protected static ?int $navigationSort = 9;

    private const KEYS = ['contact_name' => 'contact.name', 'contact_phone' => 'contact.phone', 'contact_message' => 'contact.message'];

    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(PlatformSettings::class);
        $this->form->fill(array_map(fn (string $key) => $settings->get($key), self::KEYS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Form::make([
                Section::make('التواصل للتجديد (يظهر للعملاء في التنبيهات وصفحة انتهاء الاشتراك)')->columns(2)->schema([
                    TextInput::make('contact_name')->label('الاسم')->required()->maxLength(80),
                    TextInput::make('contact_phone')->label('رقم الهاتف / واتساب')->tel()->maxLength(20),
                    Textarea::make('contact_message')->label('الرسالة')->required()->rows(2)->maxLength(300)->columnSpanFull(),
                ]),
            ])->livewireSubmitHandler('save')->footer([
                Actions::make([Action::make('save')->label('حفظ')->submit('save')]),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(PlatformSettings::class);
        $old = array_map(fn (string $key) => $settings->get($key), self::KEYS);
        foreach (self::KEYS as $field => $key) {
            $settings->set($key, $data[$field] ?? '');
        }
        app(PlatformAudit::class)->log('platform.settings_updated', null, $old, $data);
        Notification::make()->success()->title('تم الحفظ')->send();
    }
}
