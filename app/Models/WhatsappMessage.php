<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class WhatsappMessage extends Model
{
    public const STATUSES = [
        'received' => 'قيد المعالجة',
        'done' => 'نُفّذ',
        'clarify' => 'طلب توضيح',
        'denied' => 'بدون صلاحية',
        'failed' => 'فشل',
        'unauthorized' => 'رقم غير مصرح',
        'ignored' => 'تجاهل',
    ];

    protected function casts(): array
    {
        return [
            'command' => 'array',
            'result' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumber::class, 'whatsapp_number_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
