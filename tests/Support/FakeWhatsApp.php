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
        if ($this->fail) {
            throw new \RuntimeException('WhatsApp is down');
        }
        $this->sent[] = ['to' => $to, 'text' => $text];
    }

    public bool $images = false;

    /** @var array<int, array{to: string, bytes: string, mime: string, caption: string}> */
    public array $images_sent = [];

    public bool $fail = false;

    public function supportsImages(): bool
    {
        return $this->images;
    }

    public function sendImage(string $to, string $bytes, string $mimeType, string $caption): void
    {
        $this->images_sent[] = ['to' => $to, 'bytes' => $bytes, 'mime' => $mimeType, 'caption' => $caption];
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
