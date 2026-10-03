<?php

namespace Tests\Support;

use App\WhatsApp\ClaudeInterpreter;
use App\WhatsApp\Command;

/** Claude's answer, set by the test. */
class FakeClaude extends ClaudeInterpreter
{
    /** @var array<int, array{text: string, previous: ?array}> */
    public array $calls = [];

    public ?Command $answer = null;

    public function interpret(string $text, ?array $previous = null): ?Command
    {
        $this->calls[] = ['text' => $text, 'previous' => $previous];

        return $this->answer;
    }
}
