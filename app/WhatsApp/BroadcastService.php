<?php

namespace App\WhatsApp;

use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\WhatsappReminders;
use App\Models\Account;
use App\Models\WhatsappBroadcast;
use App\Models\WhatsappNumber;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * «الرسائل الجماعية»: a message (and an optional image) to exactly the subscribers a user picked.
 * Each recipient becomes one outbox row, sent slowly by the scheduler like every other message;
 * a phone appears once per broadcast and a subscriber without a phone is skipped.
 */
class BroadcastService
{
    public const MAX_RECIPIENTS = 500;

    public function __construct(private Outbox $outbox, private Audit $audit) {}

    /**
     * @param  Collection<int, Account>  $accounts  the chosen ones, nothing more
     */
    public function send(Collection $accounts, string $body, ?string $imagePath = null, ?string $imageMime = null): WhatsappBroadcast
    {
        if (! Auth::user()?->can('whatsapp.broadcast')) {
            throw new AuthorizationException('ليست لديك صلاحية الرسائل الجماعية.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new BusinessRuleException('اكتب نص الرسالة.');
        }
        if ($accounts->isEmpty()) {
            throw new BusinessRuleException('حدّد مشتركاً واحداً على الأقل.');
        }
        if ($accounts->count() > self::MAX_RECIPIENTS) {
            throw new BusinessRuleException('الحد الأقصى '.self::MAX_RECIPIENTS.' مستلم في الرسالة الواحدة.');
        }
        if (! Notifier::canSend()) {
            throw new BusinessRuleException('ربط واتساب غير مهيأ بعد (إعدادات واتساب).');
        }

        return DB::transaction(function () use ($accounts, $body, $imagePath, $imageMime) {
            $broadcast = WhatsappBroadcast::create([
                'body' => mb_substr($body, 0, 3000), 'image_path' => $imagePath, 'image_mime' => $imagePath ? ($imageMime ?? 'image/jpeg') : null,
                'created_by' => Auth::id(),
            ]);
            [$queued, $skipped] = [0, 0];
            foreach ($accounts as $account) {
                $account->loadMissing(['subscriber', 'currentPlan']);
                $phone = WhatsappReminders::phone($account);
                $text = strtr($broadcast->body, WhatsappReminders::values($account));
                $row = $phone ? $this->outbox->queue($phone, $text, 'sub_broadcast', "bc:{$broadcast->id}:".WhatsappNumber::normalize($phone), $account->id, array_filter([
                    'broadcast_id' => $broadcast->id, 'media_path' => $broadcast->image_path, 'media_mime' => $broadcast->image_mime,
                ])) : null;
                $row ? $queued++ : $skipped++;
            }
            $broadcast->update(['recipients' => $queued, 'skipped' => $skipped]);
            $this->audit->log('whatsapp.broadcast', $broadcast, null, [
                'recipients' => $queued, 'skipped' => $skipped, 'image' => $imagePath !== null, 'text' => mb_strimwidth($broadcast->body, 0, 300, '…'),
            ]);

            return $broadcast;
        });
    }

    /** How the current connection sends an image. */
    public static function imageMode(): string
    {
        $driver = app(Settings::class)->get('whatsapp.driver');
        $line = app(Settings::class)->get('whatsapp.notify_separate') ? 'notify' : 'main';
        $images = $driver === 'meta' || ($driver !== 'meta' && WahaGateway::line($line)->supportsImages());

        return $images ? 'image' : 'link';
    }
}
