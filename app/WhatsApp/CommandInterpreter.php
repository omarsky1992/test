<?php

namespace App\WhatsApp;

/**
 * The fixed rules first (instant, no cost), then the AI for everything else. A follow-up to a
 * clarifying question that the rules do not understand on its own goes to the AI together with
 * the unclear command.
 */
class CommandInterpreter implements Interpreter
{
    public function __construct(private RuleInterpreter $rules, private ClaudeInterpreter $ai, private CustomPatterns $custom)
    {
    }

    public function interpret(string $text, ?array $previous = null): ?Command
    {
        // The admin's own phrasings first, then the built-in rules, then the AI.
        if ($command = $this->custom->match($text) ?? $this->rules->interpret($text)) {
            return $command;
        }

        return $this->ai->interpret($text, $previous);
    }
}
