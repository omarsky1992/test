<?php

namespace App\WhatsApp;

/**
 * Sending replies and fetching voice notes. The live implementation is the WhatsApp Cloud API;
 * tests bind a fake.
 */
interface Gateway
{
    public function send(string $to, string $text): void;

    /**
     * @return array{0: string, 1: string} the file's bytes and its MIME type
     */
    public function downloadMedia(string $mediaId): array;
}
