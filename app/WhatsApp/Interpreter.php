<?php

namespace App\WhatsApp;

interface Interpreter
{
    /**
     * @param  array{text: string, question: string}|null  $previous  the last unclear command from the same number and the question asked about it
     */
    public function interpret(string $text, ?array $previous = null): ?Command;
}
