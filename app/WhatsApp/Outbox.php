<?php

namespace App\WhatsApp;

use App\Models\Account;
use App\Models\WhatsappNumber;
use App\Models\WhatsappOutbox;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Messages the system sends are queued here and sent a few at a time by the scheduler, with a
 * pause between them, a daily cap and (for subscribers) only during the day. A reference makes
 * the same alert or reminder impossible to queue twice.
 */
class Outbox
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  array{payment_id?: int, broadcast_id?: int, media_path?: string, media_mime?: string}  $extra
     */
    public function queue(string $phone, string $body, string $kind, ?string $reference = null, ?int $accountId = null, array $extra = []): ?WhatsappOutbox
    {
        $phone = WhatsappNumber::normalize($phone);
        if (strlen($phone) < 10 || trim($body) === '') {
            return null;
        }
        if ($reference !== null && WhatsappOutbox::where('reference', $reference)->exists()) {
            return null;
        }
        if ($accountId !== null && self::forSubscriber($kind) && self::muted()->whereKey($accountId)->exists()) {
            return null; // «إيقاف رسائل الواتساب» is on for this subscriber
        }
        try {
            // Its own savepoint: a duplicate never breaks the operation that queued it.
            return DB::transaction(fn () => WhatsappOutbox::create([
                'to_phone' => $phone, 'body' => $body, 'kind' => $kind, 'reference' => $reference ? mb_substr($reference, 0, 120) : null,
                'account_id' => $accountId, 'status' => 'pending', 'created_by' => Auth::id(),
                ...array_intersect_key($extra, array_flip(['payment_id', 'broadcast_id', 'media_path', 'media_mime'])),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Kinds that reach the subscriber (the staff alerts and tests never do). */
    public static function forSubscriber(string $kind): bool
    {
        return (str_starts_with($kind, 'sub_') && $kind !== 'sub_test') || $kind === 'reminder';
    }

    /** Accounts whose subscriber has «إيقاف رسائل الواتساب» on. */
    private static function muted()
    {
        return Account::whereHas('subscriber', fn ($q) => $q->where('whatsapp_muted', true));
    }

    /** With a second phone for the subscribers: «notify» sends their messages (automatic and reminders), «main» the rest. */
    public function separateLines(): bool
    {
        return (bool) $this->settings->get('whatsapp.notify_separate');
    }

    /**
     * Sends what is due on one line ('all' when there is a single phone; each line has its own
     * batch and daily cap). Returns [sent, failed].
     *
     * @return array{0: int, 1: int}
     */
    public function dispatch(Gateway $gateway, ?CarbonImmutable $now = null, string $line = 'all'): array
    {
        $now ??= CarbonImmutable::now();
        $scope = fn ($query) => match ($line) {
            'main' => $query->where('kind', 'not like', 'sub\\_%')->where('kind', '<>', 'reminder'),
            'notify' => $query->where(fn ($q) => $q->where('kind', 'like', 'sub\\_%')->orWhere('kind', 'reminder')),
            default => $query,
        };
        $sentToday = $scope(WhatsappOutbox::where('status', 'sent')->where('sent_at', '>=', $now->startOfDay()))->count();
        $room = min((int) $this->settings->get('whatsapp.batch_size'), (int) $this->settings->get('whatsapp.daily_limit') - $sentToday);
        if ($room <= 0) {
            return [0, 0];
        }

        // Waiting messages of a subscriber whose messages were stopped since are dropped, never sent.
        $scope(WhatsappOutbox::where('status', 'pending'))->whereIn('account_id', self::muted()->select('accounts.id'))
            ->where(fn ($q) => $q->where('kind', 'like', 'sub\\_%')->orWhere('kind', 'reminder'))->where('kind', '<>', 'sub_test')
            ->update(['status' => 'skipped', 'error' => 'رسائل الواتساب موقوفة لهذا المشترك']);

        $query = $scope(WhatsappOutbox::where('status', 'pending')->orderBy('id'));
        if (! $this->duringDay($now)) {
            // At night only the staff alerts go out; subscribers are never messaged at night.
            $query->where('kind', 'like', 'alert_%');
        }
        [$sent, $failed] = [0, 0];
        [$min, $max] = config('whatsapp.send_delay');
        foreach ($query->limit($room)->get() as $i => $message) {
            if ($i > 0 && $max > 0) {
                sleep(random_int(max(0, $min), max($min, $max)));
            }
            try {
                $this->deliver($gateway, $message);
                $message->update(['status' => 'sent', 'sent_at' => $now, 'attempts' => $message->attempts + 1, 'error' => null]);
                $sent++;
            } catch (\Throwable $e) {
                $attempts = $message->attempts + 1;
                $message->update(['attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 500), 'status' => $attempts >= 3 ? 'failed' : 'pending']);
                $failed++;
            }
        }

        return [$sent, $failed];
    }

    /**
     * Text, or an image or a PDF with the text as its caption. A connection that cannot send files (the
     * free WAHA Core) gets the text with a link to the file instead.
     */
    private function deliver(Gateway $gateway, WhatsappOutbox $message): void
    {
        if ($message->media_path === null) {
            $gateway->send($message->to_phone, $message->body);

            return;
        }
        $disk = Storage::disk('local');
        $pdf = $message->media_mime === 'application/pdf';
        if ($gateway->supportsImages() && $disk->exists($message->media_path)) {
            $pdf
                ? $gateway->sendFile($message->to_phone, $disk->get($message->media_path), 'application/pdf', basename($message->media_path), $message->body)
                : $gateway->sendImage($message->to_phone, $disk->get($message->media_path), $message->media_mime ?? 'image/jpeg', $message->body);

            return;
        }
        $link = match (true) {
            $message->broadcast_id !== null => URL::temporarySignedRoute('whatsapp.broadcast.image', now()->addDays(30), ['broadcast' => $message->broadcast_id]),
            $disk->exists($message->media_path) => URL::temporarySignedRoute('whatsapp.outbox.media', now()->addDays(30), ['message' => $message->id]),
            default => null,
        };
        $gateway->send($message->to_phone, $message->body.($link ? "\n\n".($pdf ? '📄 الملف' : '🖼️ الصورة').": {$link}" : ''));
    }

    public function duringDay(CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone(config('app.timezone'))->format('G');

        return $hour >= (int) $this->settings->get('whatsapp.send_from_hour') && $hour < (int) $this->settings->get('whatsapp.send_until_hour');
    }
}
