<?php

namespace App\WhatsApp;

/**
 * The outcome of one command: the message status, the reply sent back and what changed.
 */
final class Result
{
    public function __construct(public string $status, public string $reply, public array $changes = [])
    {
    }

    public static function done(string $reply, array $changes = []): self
    {
        return new self('done', $reply, $changes);
    }

    public static function clarify(string $question): self
    {
        return new self('clarify', $question);
    }

    public static function failed(string $reply): self
    {
        return new self('failed', $reply);
    }

    public static function denied(): self
    {
        return new self('denied', '⛔ ما عندك صلاحية لهذه العملية.');
    }
}
