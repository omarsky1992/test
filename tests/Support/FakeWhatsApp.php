<?php

namespace Tests\Support;

use App\WhatsApp\Gateway;
use App\WhatsApp\Transcriber;

/**
 * Stands in for Meta, the transcription service and Claude: records what would be sent and
 * returns what each test sets up.
 */
class FakeWhatsApp implements Gateway, Transcriber
{
    /** @var array<int, array{to: string, text: string}> */
    public array $sent = [];

    public array $downloads = [];

    public array $transcribed = [];

    public string $transcript = '';

    public function send(string $to, string $text): void
    {
        $this->sent[] = ['to' => $to, 'text' => $text];
    }

    public function downloadMedia(string $mediaId): array
    {
        $this->downloads[] = $mediaId;

        return ['OGG-BYTES', 'audio/ogg; codecs=opus'];
    }

    public function transcribe(string $audio, string $mimeType): string
    {
        $this->transcribed[] = $audio;

        return $this->transcript;
    }

    public function lastReply(): ?string
    {
        return $this->sent === [] ? null : end($this->sent)['text'];
    }
}
