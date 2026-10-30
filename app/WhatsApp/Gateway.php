<?php

namespace App\WhatsApp;

/**
 * Sending replies and fetching voice notes. The live implementation is the WhatsApp Cloud API;
 * tests bind a fake.
 */
interface Gateway
{
    public function send(string $to, string $text): void;

    /** Whether this connection sends real images (otherwise the image goes as a link in the text). */
    public function supportsImages(): bool;

    /** An image with its caption; only called when supportsImages() is true. */
    public function sendImage(string $to, string $bytes, string $mimeType, string $caption): void;

    /** A document (PDF) with its caption; only called when supportsImages() is true. */
    public function sendFile(string $to, string $bytes, string $mimeType, string $fileName, string $caption): void;

    /**
     * @return array{0: string, 1: string} the file's bytes and its MIME type
     */
    public function downloadMedia(string $mediaId): array;
}
