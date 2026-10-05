<?php

namespace App\WhatsApp;

use App\Models\WhatsappOutbox;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Messages the system sends are queued here and sent a few at a time by the scheduler, with a
 * pause between them, a daily cap and (for subscribers) only during the day. A reference makes
 * the same alert or reminder impossible to queue twice.
 */
class Outbox
{
    public function __construct(private Settings $settings)
    {
    }

    public function queue(string $phone, string $body, string $kind, ?string $reference = null, ?int $accountId = null): ?WhatsappOutbox
    {
        $phone = \App\Models\WhatsappNumber::normalize($phone);
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
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Sends what is due. Returns [sent, failed].
     *
     * @return array{0: int, 1: int}
     */
    public function dispatch(Gateway $gateway, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $sentToday = WhatsappOutbox::where('status', 'sent')->where('sent_at', '>=', $now->startOfDay())->count();
        $room = min((int) $this->settings->get('whatsapp.batch_size'), (int) $this->settings->get('whatsapp.daily_limit') - $sentToday);
        if ($room <= 0) {
            return [0, 0];
        }

        $query = WhatsappOutbox::where('status', 'pending')->orderBy('id');
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
                $gateway->send($message->to_phone, $message->body);
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

    public function duringDay(CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone(config('app.timezone'))->format('G');

        return $hour >= (int) $this->settings->get('whatsapp.send_from_hour') && $hour < (int) $this->settings->get('whatsapp.send_until_hour');
    }
}
