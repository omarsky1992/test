<?php

namespace App\WhatsApp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Speech to text through an OpenAI-compatible transcription endpoint (whisper-1 by default;
 * the URL and model can point at any compatible provider).
 */
class HttpTranscriber implements Transcriber
{
    public function transcribe(string $audio, string $mimeType): string
    {
        if (! self::configured()) {
            throw new RuntimeException('Voice transcription key is not set.');
        }
        $extension = match (true) {
            str_contains($mimeType, 'ogg') => 'ogg',
            str_contains($mimeType, 'mpeg') => 'mp3',
            str_contains($mimeType, 'mp4'), str_contains($mimeType, 'aac') => 'm4a',
            str_contains($mimeType, 'amr') => 'amr',
            default => 'ogg',
        };

        $response = Http::withToken(config('whatsapp.transcribe.api_key'))->timeout(60)
            ->attach('file', $audio, "voice.{$extension}")
            ->post(config('whatsapp.transcribe.url'), [
                'model' => config('whatsapp.transcribe.model'),
                'language' => 'ar',
                'response_format' => 'json',
            ])->throw();

        return trim((string) $response->json('text'));
    }

    public static function configured(): bool
    {
        return filled(config('whatsapp.transcribe.api_key'));
    }
}
