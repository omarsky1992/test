<?php

namespace App\WhatsApp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudApiGateway implements Gateway
{
    public function send(string $to, string $text): void
    {
        $this->http()->post($this->graph(config('whatsapp.phone_number_id').'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)],
        ])->throw();
    }

    public function downloadMedia(string $mediaId): array
    {
        $meta = $this->http()->get($this->graph($mediaId))->throw()->json();
        if (empty($meta['url'])) {
            throw new RuntimeException('WhatsApp media has no URL.');
        }
        $file = $this->http()->get($meta['url'])->throw();

        return [$file->body(), (string) ($meta['mime_type'] ?? $file->header('Content-Type'))];
    }

    public static function configured(): bool
    {
        return filled(config('whatsapp.access_token')) && filled(config('whatsapp.phone_number_id'));
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        if (! self::configured()) {
            throw new RuntimeException('WHATSAPP_ACCESS_TOKEN / WHATSAPP_PHONE_NUMBER_ID are not set.');
        }

        return Http::withToken(config('whatsapp.access_token'))->timeout(20)->retry(2, 500, throw: false);
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('whatsapp.graph_version').'/'.$path;
    }
}
