<?php

namespace App\WhatsApp;

use Illuminate\Support\Facades\Http;

/**
 * Free speech to text on our own server: the «whisper» service in deploy/docker-compose.yml
 * (whisper-asr-webservice with faster-whisper). Nothing leaves the server and nothing is paid.
 */
class LocalWhisperTranscriber implements Transcriber
{
    public function transcribe(string $audio, string $mimeType): string
    {
        $response = Http::timeout(180)
            ->attach('audio_file', $audio, 'voice.'.(str_contains($mimeType, 'mpeg') ? 'mp3' : 'ogg'))
            ->post(rtrim((string) config('whatsapp.transcribe.local_url'), '/').'/asr?'.http_build_query([
                'task' => 'transcribe', 'language' => 'ar', 'output' => 'json', 'encode' => 'true',
            ]))->throw();

        return trim((string) ($response->json('text') ?? $response->body()));
    }

    public static function configured(): bool
    {
        return filled(config('whatsapp.transcribe.local_url'));
    }
}
