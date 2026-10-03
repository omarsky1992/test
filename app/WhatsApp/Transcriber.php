<?php

namespace App\WhatsApp;

interface Transcriber
{
    /** Turns a voice note into text (Iraqi Arabic). */
    public function transcribe(string $audio, string $mimeType): string;
}
