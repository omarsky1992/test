<?php

namespace App\Filament\Pages;

use App\Models\WhatsappOutbox;
use App\WhatsApp\TestMessages;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * «اختبار رسائل الواتساب»: send any template (activation, expiring, expired, debt, payment) or a free
 * text, filled with made-up data, to any number to see how it arrives. Linked to no subscriber,
 * debt or account.
 */
class WhatsappTest extends Page
{
    protected string $view = 'filament.pages.whatsapp-test';

    protected static ?string $slug = 'whatsapp-test';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'اختبار رسائل الواتساب';

    protected static ?string $title = 'اختبار رسائل الواتساب';

    public string $phone = '';

    public string $line = 'main';

    public string $event = 'renewal';

    public string $name = 'مشترك تجريبي';

    public string $device = 'test_user';

    public string $plan = 'FIBER 35';

    public string $days = '30';

    public string $amount = '35000';

    public string $custom = '';

    public static function canAccess(): bool
    {
        return auth()->user()->can('whatsapp.manage');
    }

    private function sample(): array
    {
        $digits = fn (string $v) => (int) preg_replace('/\D/', '', strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));

        return ['name' => $this->name, 'device' => $this->device, 'plan' => $this->plan, 'days' => $digits($this->days), 'amount' => $digits($this->amount)];
    }

    public function preview(): ?string
    {
        return app(TestMessages::class)->text($this->event, $this->sample(), $this->custom);
    }

    public function send(): void
    {
        abort_unless(static::canAccess(), 403);
        $text = $this->preview();
        if ($text === null) {
            Notification::make()->danger()->title('لا يوجد نص')->body('فعّل قالب هذه الرسالة من «قوالب الرسائل» أو اكتب نصاً حراً.')->send();

            return;
        }
        $message = app(TestMessages::class)->send($this->phone, $text, $this->line);
        if (! $message) {
            Notification::make()->danger()->title('رقم غير صحيح')->body('اكتب الرقم كاملاً مع رمز الدولة، مثال: 9647701234567')->send();

            return;
        }
        Notification::make()->success()->title('أُضيفت رسالة الاختبار')
            ->body($this->line === 'main' ? 'تصل خلال دقيقة من الرقم الرئيسي.' : 'تصل من خط المشتركين في ساعات الإرسال.')->send();
    }

    protected function getViewData(): array
    {
        return [
            'events' => TestMessages::events(),
            'lines' => TestMessages::LINES,
            'text' => $this->preview(),
            'recent' => WhatsappOutbox::whereIn('kind', TestMessages::KINDS)->latest('id')->limit(10)->get(),
        ];
    }
}
