<?php

namespace App\WhatsApp;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Services\Audit;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * «اختبار رسائل الواتساب»: the admin's templates filled with made-up subscriber data and sent to any
 * number, to see how a subscriber receives them. Linked to nothing: no subscriber, account, debt or
 * payment is read or written; only an outbox row (no account) and an audit entry.
 */
class TestMessages
{
    /** Lines a test can go through: the main phone (any hour) or as a subscriber gets it. */
    public const LINES = [
        'main' => 'الرقم الرئيسي (يصل خلال دقيقة، بأي وقت)',
        'subscriber' => 'كما يستلمها المشترك (خط المشتركين، في ساعات الإرسال فقط)',
    ];

    public const KINDS = ['main' => 'alert_test', 'subscriber' => 'sub_test'];

    public function __construct(private Outbox $outbox, private Audit $audit) {}

    /** @return array<string, string> event => label, plus a free text */
    public static function events(): array
    {
        return MessageTemplateResource::EVENTS + ['custom' => 'نص حر'];
    }

    /**
     * The template's placeholders filled with the sample.
     *
     * @param  array{name?: ?string, device?: ?string, plan?: ?string, days?: int|string|null, amount?: int|string|null}  $sample
     * @return array<string, string>
     */
    public static function values(array $sample): array
    {
        $days = max(0, (int) ($sample['days'] ?? 0));
        $amount = max(0, (int) ($sample['amount'] ?? 0));

        return [
            '{الاسم}' => trim((string) ($sample['name'] ?? '')) ?: 'مشترك تجريبي',
            '{الفئة}' => trim((string) ($sample['plan'] ?? '')) ?: 'FIBER 35',
            '{الأيام}' => (string) $days,
            '{المبلغ}' => Money::format($amount),
            '{تاريخ_الانتهاء}' => now()->addDays($days)->setTimezone(config('app.timezone'))->format('Y/m/d'),
            '{اليوزر}' => trim((string) ($sample['device'] ?? '')) ?: 'test_user',
            '{المبلغ_المستلم}' => Money::format($amount),
            '{طريقة_الدفع}' => 'نقداً',
            '{تاريخ_الدفع}' => now()->setTimezone(config('app.timezone'))->format('Y/m/d H:i'),
            '{رقم_الوصل}' => 'TEST-0001',
            '{رقم_العملية}' => '—',
        ];
    }

    /** The text the number would get, or null when the event has no active template. */
    public function text(string $event, array $sample, ?string $custom = null): ?string
    {
        if ($event === 'custom') {
            return trim((string) $custom) !== '' ? strtr((string) $custom, self::values($sample)) : null;
        }
        $template = MessageTemplate::where('auto_event', $event)->where('is_active', true)->first();

        return $template?->render(self::values($sample));
    }

    /** Queues one test message; null when the number or the text is not valid. */
    public function send(string $phone, string $text, string $line = 'main'): ?WhatsappOutbox
    {
        $kind = self::KINDS[$line] ?? self::KINDS['main'];
        $message = $this->outbox->queue($phone, $text, $kind, 'test:'.Str::uuid());
        if ($message) {
            $this->audit->log('whatsapp.test_sent', $message, null, ['to' => WhatsappNumber::normalize($phone), 'line' => $line]);
        }

        return $message;
    }
}
