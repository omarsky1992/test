<?php

namespace App\WhatsApp;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\WhatsappNumber;
use App\Services\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The admin's list of numbers allowed to send commands. Every change is audited.
 */
class NumberRegistry
{
    public function __construct(private Audit $audit)
    {
    }

    public function add(string $phone, User $user, ?string $label = null, bool $active = true, bool $alerts = false): WhatsappNumber
    {
        $normalized = $this->validPhone($phone);

        return DB::transaction(function () use ($normalized, $user, $label, $active, $alerts) {
            if (WhatsappNumber::where('phone', $normalized)->exists()) {
                throw new BusinessRuleException('هذا الرقم مضاف مسبقاً.');
            }
            $number = WhatsappNumber::create([
                'phone' => $normalized, 'label' => $label, 'user_id' => $user->id, 'is_active' => $active, 'receives_alerts' => $alerts, 'created_by' => Auth::id(),
            ]);
            $this->audit->log('whatsapp_number.added', $number, null, ['phone' => $normalized, 'label' => $label, 'user' => $user->name, 'active' => $active, 'alerts' => $alerts]);

            return $number;
        });
    }

    public function update(WhatsappNumber $number, string $phone, User $user, ?string $label, bool $active, ?bool $alerts = null): WhatsappNumber
    {
        $normalized = $this->validPhone($phone);
        if (WhatsappNumber::where('phone', $normalized)->whereKeyNot($number->id)->exists()) {
            throw new BusinessRuleException('هذا الرقم مضاف مسبقاً.');
        }
        $original = $number->getAttributes();
        $number->update(['phone' => $normalized, 'label' => $label, 'user_id' => $user->id, 'is_active' => $active, 'receives_alerts' => $alerts ?? $number->receives_alerts]);
        $this->audit->changes('whatsapp_number.updated', $number, $original);

        return $number;
    }

    public function setActive(WhatsappNumber $number, bool $active): WhatsappNumber
    {
        $original = $number->getAttributes();
        $number->update(['is_active' => $active]);
        $this->audit->changes($active ? 'whatsapp_number.enabled' : 'whatsapp_number.disabled', $number, $original);

        return $number;
    }

    public function delete(WhatsappNumber $number): void
    {
        DB::transaction(function () use ($number) {
            $this->audit->log('whatsapp_number.deleted', $number, ['phone' => $number->phone, 'label' => $number->label, 'user_id' => $number->user_id], null);
            $number->delete();
        });
    }

    private function validPhone(string $phone): string
    {
        $normalized = WhatsappNumber::normalize($phone);
        if (strlen($normalized) < 8 || strlen($normalized) > 15) {
            throw new BusinessRuleException('رقم الهاتف غير صحيح. اكتبه مثل 07701234567 أو 9647701234567.');
        }

        return $normalized;
    }
}
