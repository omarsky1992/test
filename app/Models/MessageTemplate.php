<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

#[Unguarded]
class MessageTemplate extends Model
{
    /** Placeholders an admin may use, with what each becomes. */
    public const PLACEHOLDERS = [
        '{الاسم}' => 'اسم المشترك',
        '{الفئة}' => 'الفئة',
        '{الأيام}' => 'الأيام المتبقية',
        '{المبلغ}' => 'المبلغ المتبقي عليه',
        '{تاريخ_الانتهاء}' => 'تاريخ انتهاء الاشتراك',
        '{ساعة_الانتهاء}' => 'ساعة انتهاء الاشتراك',
        '{اليوزر}' => 'اسم الجهاز (اليوزر)',
        '{المبلغ_المستلم}' => 'المبلغ المستلم (رسالة الدفعة)',
        '{طريقة_الدفع}' => 'طريقة الدفع (رسالة الدفعة)',
        '{تاريخ_الدفع}' => 'تاريخ الدفعة (رسالة الدفعة)',
        '{رقم_الوصل}' => 'رقم سند القبض (رسالة الدفعة)',
        '{رقم_العملية}' => 'رقم العملية إن وُجد (رسالة الدفعة)',
    ];

    /** How the message goes: its text alone, or an image or a PDF with the text as caption. */
    public const MEDIA_TYPES = ['text' => 'نص فقط', 'image' => 'صورة مع النص', 'pdf' => 'ملف PDF مع النص'];

    /** Hour of a time, e.g. «10:30 م». */
    public static function hour(?DateTimeInterface $at): string
    {
        if ($at === null) {
            return '—';
        }
        $at = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));

        return $at->format('g:i').' '.($at->format('A') === 'AM' ? 'ص' : 'م');
    }

    /**
     * The outbox fields of the template's file, when it is sent as an image or a PDF.
     *
     * @return array{media_path?: string, media_mime?: string}
     */
    public function media(): array
    {
        return $this->media_type !== 'text' && $this->media_path
            ? ['media_path' => $this->media_path, 'media_mime' => $this->media_mime ?? ($this->media_type === 'pdf' ? 'application/pdf' : 'image/jpeg')]
            : [];
    }

    protected static function booted(): void
    {
        // The file's type from its stored name (the upload accepts only PDF, JPEG, PNG and WebP).
        static::saving(function (MessageTemplate $template) {
            $template->media_mime = $template->media_path ? match (strtolower(pathinfo($template->media_path, PATHINFO_EXTENSION))) {
                'pdf' => 'application/pdf', 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg',
            } : null;
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  array<string, string>  $values  placeholder => value
     */
    public function render(array $values): string
    {
        return strtr($this->body, $values);
    }
}
