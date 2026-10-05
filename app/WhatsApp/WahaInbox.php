<?php

namespace App\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Turns WAHA's «message» event into the message shape the Inbox understands. Messages from the
 * linked phone itself, groups, channels and status updates are ignored. When WhatsApp hides the
 * sender's number (a «@lid» id with no number beside it) the message is dropped: an unknown number
 * is never trusted.
 */
class WahaInbox
{
    public function __construct(private Inbox $inbox)
    {
    }

    public function handle(array $event): void
    {
        if (($event['event'] ?? null) !== 'message' || ! is_array($payload = $event['payload'] ?? null)) {
            return;
        }
        if (($payload['fromMe'] ?? false) || blank($payload['id'] ?? null)) {
            return;
        }
        $from = (string) ($payload['from'] ?? '');
        if (str_ends_with($from, '@g.us') || str_ends_with($from, '@newsletter') || str_ends_with($from, '@broadcast')) {
            return;
        }
        $phone = self::phone($payload);
        if ($phone === null) {
            Log::info('WhatsApp message without a visible number ignored', ['from' => $from]);

            return;
        }

        $media = is_array($payload['media'] ?? null) ? $payload['media'] : [];
        $kind = (string) ($payload['_data']['type'] ?? $payload['type'] ?? '');
        // A voice note even when WAHA could not attach the file (the Inbox then asks to send it again).
        $isAudio = ($payload['hasMedia'] ?? false)
            && (str_starts_with((string) ($media['mimetype'] ?? ''), 'audio') || in_array($kind, ['ptt', 'audio'], true));

        $this->inbox->receive(array_filter([
            'id' => (string) $payload['id'],
            'from' => $phone,
            'timestamp' => isset($payload['timestamp']) ? (string) $payload['timestamp'] : null,
            'type' => $isAudio ? 'audio' : (filled($payload['body'] ?? null) ? 'text' : 'other'),
            'text' => $isAudio ? null : ['body' => (string) ($payload['body'] ?? '')],
            'audio' => $isAudio ? ['id' => (string) ($media['url'] ?? ''), 'mime_type' => (string) ($media['mimetype'] ?? '')] : null,
        ], fn ($v) => $v !== null));
    }

    /** The sender's phone number, from the chat id or, for hidden («@lid») ids, the number WhatsApp sent beside it. */
    public static function phone(array $payload): ?string
    {
        $data = is_array($payload['_data'] ?? null) ? $payload['_data'] : [];
        $candidates = [
            $payload['from'] ?? null,
            $data['key']['remoteJidAlt'] ?? null,
            $data['key']['senderPn'] ?? null,
            $data['Info']['SenderAlt'] ?? null,
            $data['Info']['Sender'] ?? null,
        ];
        foreach ($candidates as $id) {
            if (is_string($id) && (str_ends_with($id, '@c.us') || str_ends_with($id, '@s.whatsapp.net'))) {
                $digits = WahaGateway::digits($id);
                if (strlen($digits) >= 10) {
                    return $digits;
                }
            }
        }

        return null;
    }
}
