<?php

namespace App\WhatsApp;

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
     * Text, or an image with the text as its caption. A connection that cannot send images (the free
     * WAHA Core) gets the text with a link to the image instead.
     */
    private function deliver(Gateway $gateway, WhatsappOutbox $message): void
    {
        if ($message->media_path === null) {
            $gateway->send($message->to_phone, $message->body);

            return;
        }
        $disk = Storage::disk('local');
        if ($gateway->supportsImages() && $disk->exists($message->media_path)) {
            $gateway->sendImage($message->to_phone, $disk->get($message->media_path), $message->media_mime ?? 'image/jpeg', $message->body);

            return;
        }
        $link = $message->broadcast_id
            ? URL::temporarySignedRoute('whatsapp.broadcast.image', now()->addDays(30), ['broadcast' => $message->broadcast_id])
            : null;
        $gateway->send($message->to_phone, $message->body.($link ? "\n\n🖼️ الصورة: {$link}" : ''));
    }

    public function duringDay(CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone(config('app.timezone'))->format('G');

        return $hour >= (int) $this->settings->get('whatsapp.send_from_hour') && $hour < (int) $this->settings->get('whatsapp.send_until_hour');
    }
}
