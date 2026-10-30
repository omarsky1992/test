<?php

namespace App\WhatsApp;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Services\Audit;
use App\Services\ReceiptImage;
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
            '{ساعة_الانتهاء}' => MessageTemplate::hour(now()->addDays($days)),
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

    /**
     * The event template's image or PDF, sent with the test as the subscriber would get it; for the
     * payment message with the receipt chosen, a made-up receipt drawn from the sample.
     */
    public function media(string $event, array $sample = []): array
    {
        $template = $event === 'custom' ? null : MessageTemplate::where('auto_event', $event)->where('is_active', true)->first();
        if ($format = $template?->receiptFormat()) {
            $receipts = app(ReceiptImage::class);
            $values = self::values($sample);

            return $receipts->store($receipts->sample($values['{الاسم}'], $values['{اليوزر}'], max(0, (int) ($sample['amount'] ?? 0))), $format, 'whatsapp/tests');
        }

        return $template?->media() ?? [];
    }

    /** What goes with the text, shown on the page before sending. */
    public static function mediaLabel(string $event): ?string
    {
        $template = $event === 'custom' ? null : MessageTemplate::where('auto_event', $event)->where('is_active', true)->first();

        return match (true) {
            $template?->receiptFormat() === 'png' => 'تُرسل معها صورة الوصل (وصل تجريبي)',
            $template?->receiptFormat() === 'pdf' => 'يُرسل معها الوصل PDF (وصل تجريبي)',
            ($template?->media()['media_mime'] ?? null) === 'application/pdf' => 'يُرسل معها ملف PDF (من قالب الرسالة)',
            $template !== null && $template->media() !== [] => 'تُرسل معها صورة (من قالب الرسالة)',
            default => null,
        };
    }

    /** Queues one test message; null when the number or the text is not valid. */
    public function send(string $phone, string $text, string $line = 'main', array $media = []): ?WhatsappOutbox
    {
        $kind = self::KINDS[$line] ?? self::KINDS['main'];
        $message = $this->outbox->queue($phone, $text, $kind, 'test:'.Str::uuid(), null, $media);
        if ($message) {
            $this->audit->log('whatsapp.test_sent', $message, null, ['to' => WhatsappNumber::normalize($phone), 'line' => $line]);
        }

        return $message;
    }
}
