<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class CustodyHandoverRequest extends Model
{
    public const STATUSES = ['pending' => 'بانتظار تأكيد المدير', 'approved' => 'تم الاستلام', 'rejected' => 'مرفوض'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'requested_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(FundTransfer::class, 'fund_transfer_id');
    }
}
