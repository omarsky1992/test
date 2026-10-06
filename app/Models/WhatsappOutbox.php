<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class WhatsappOutbox extends Model
{
    protected $table = 'whatsapp_outbox';

    public const KINDS = [
        'alert_must_activate' => 'تنبيه: يجب التفعيل',
        'alert_secondary_expiring' => 'تنبيه: دين ثانوي ينتهي قريباً',
        'sub_renewal' => 'للمشترك: تم التفعيل',
        'sub_expiring' => 'للمشترك: قرب الانتهاء',
        'sub_expired' => 'للمشترك: انتهى الاشتراك',
        'sub_debt' => 'للمشترك: تذكير بالدين',
        'reminder' => 'تذكير يدوي',
        'alert_stuck_reply' => 'رد: تعذّرت المعالجة',
    ];

    public const STATUSES = ['pending' => 'بالانتظار', 'sent' => 'أُرسلت', 'failed' => 'فشلت', 'skipped' => 'أُلغيت'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
