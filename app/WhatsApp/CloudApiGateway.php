<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
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

    public function supportsImages(): bool
    {
        return true;
    }

    public function sendImage(string $to, string $bytes, string $mimeType, string $caption): void
    {
        $media = $this->http()->attach('file', $bytes, 'image.'.(explode('/', $mimeType)[1] ?? 'jpg'), ['Content-Type' => $mimeType])
            ->post($this->graph(config('whatsapp.phone_number_id').'/media'), ['messaging_product' => 'whatsapp', 'type' => $mimeType])->throw();
        $this->http()->post($this->graph(config('whatsapp.phone_number_id').'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => ['id' => $media->json('id'), 'caption' => mb_substr($caption, 0, 1000)],
        ])->throw();
    }

    public function sendFile(string $to, string $bytes, string $mimeType, string $fileName, string $caption): void
    {
        $media = $this->http()->attach('file', $bytes, $fileName, ['Content-Type' => $mimeType])
            ->post($this->graph(config('whatsapp.phone_number_id').'/media'), ['messaging_product' => 'whatsapp', 'type' => $mimeType])->throw();
        $this->http()->post($this->graph(config('whatsapp.phone_number_id').'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => ['id' => $media->json('id'), 'filename' => $fileName, 'caption' => mb_substr($caption, 0, 1000)],
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

    private function http(): PendingRequest
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
