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
                'vad_filter' => 'true', 'initial_prompt' => self::hint(),
            ]))->throw();

        return trim((string) ($response->json('text') ?? $response->body()));
    }

    /**
     * Words the model should expect: the commands in Iraqi dialect and the names of the subscribers
     * most likely to be mentioned (owing money or ending soon). Whisper spells them right far more often.
     */
    public static function hint(): string
    {
        $phrases = 'فعلته سبع أيام، فعلته شهر، دفع خمسة وثلاثين ألف، سجل دين على، دين ثانوي، دين أولي، مناقلة، امسح دين، '
            .'شريت كيبل متر، راوتر، بعت راوتر، الديون الثانوية، المتأخرين، مبيعات اليوم، يجب التفعيل.';
        $names = \App\Models\Subscriber::query()
            ->whereHas('accounts', fn ($a) => $a->whereHas('debts', fn ($d) => $d->whereIn('status', ['open', 'partial']))
                ->orWhereRaw(\App\Support\SubscriberStatus::ENDS.' between ? and ?', [now()->subDays(3), now()->addDays(7)]))
            ->latest('updated_at')->limit(25)->pluck('full_name')->implode('، ');

        return mb_substr(trim($phrases.' '.CustomPatterns::words().' '.$names), 0, 600);
    }

    public static function configured(): bool
    {
        return filled(config('whatsapp.transcribe.local_url'));
    }
}
