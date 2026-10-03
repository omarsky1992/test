<?php

namespace App\WhatsApp;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;

/**
 * Understands free text in Iraqi Arabic with Claude and returns a Command. The answer is forced
 * into a JSON schema (structured outputs), so it is either a valid command or nothing. Requests
 * opt into server-side fallbacks: a request the model declines is retried on a fallback model
 * instead of failing.
 */
class ClaudeInterpreter implements Interpreter
{
    private const SYSTEM = <<<'TXT'
        You read commands sent over WhatsApp, in Iraqi Arabic dialect, to the management system of an
        internet (FTTH) agent, and convert each into one JSON command. Never invent data. Amounts are
        whole Iraqi dinars: «35 الف» = 35000, «5 آلاف» = 5000, «ربع مليون» = 250000, «ورقة» = 1000.
        Days: «سبع أيام» = 7, «اسبوع» = 7, «شهر» = 30.

        Intents:
        - activate: the agent renewed/activated a subscriber ("محمد رمضان فعلته سبع أيام"). subscriber = the
          name, phone or username exactly as written, without verbs or filler; days = activation length.
        - payment: a subscriber paid money ("علي حسين دفع 25 الف"). subscriber and amount.
        - purchase: the agent bought things ("شريت كيبل 30 متر سعر المتر 5 آلاف وراوتر بـ 40 الف"). One item
          per thing bought: description (include the quantity and unit, e.g. "كيبل 30 متر"), quantity,
          unit_price, total (= quantity × unit_price when both are given; total alone when only a total is given).
        - void_debt: delete/cancel a subscriber's debt ("امسح دين محمد"). subscriber; amount only if stated.
        - query: a question. query = secondary_debts (الديون الثانوية), primary_debts (الديون الأولية),
          late (المتأخرين), activated_today (المفعلين اليوم), sales_today (مبيعات اليوم),
          purchases_today (مشتريات/مصاريف اليوم), custody (عهد الموظفين), advances (سلف الموظفين),
          subscriber_debt (how much a given subscriber owes; also set subscriber).
        - clarify: a command whose required detail is missing or ambiguous (activation without days, payment
          without amount, purchase item without a price). Put one short question in Iraqi Arabic in "question".
        - unknown: anything else (greetings, unrelated text). Put a short Iraqi Arabic reply in "question"
          listing what can be asked.

        Fill only the fields the intent needs; set the others to null (items to an empty list).
        If a previous unclear command and the question asked about it are given, the new message is the
        answer: combine them into one complete command.
        TXT;

    public function interpret(string $text, ?array $previous = null): ?Command
    {
        if (! self::configured()) {
            return null;
        }

        try {
            $client = new Client(apiKey: config('whatsapp.ai.api_key'), requestOptions: ['timeout' => 30.0, 'maxRetries' => 2]);
            $message = $client->beta->messages->create(...$this->request($text, $previous));
        } catch (\Throwable $e) {
            Log::warning('WhatsApp interpreter failed: '.$e->getMessage());

            return null;
        }

        if ($message->stopReason === 'refusal') {
            return null;
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $this->parse($block->text);
            }
        }

        return null;
    }

    /**
     * The arguments of the beta messages call.
     */
    public function request(string $text, ?array $previous = null): array
    {
        $content = $previous
            ? "Previous unclear command: {$previous['text']}\nQuestion asked: {$previous['question']}\nNew message: {$text}"
            : $text;

        return [
            'model' => config('whatsapp.ai.model'),
            'maxTokens' => 4000,
            'system' => self::SYSTEM,
            'messages' => [['role' => 'user', 'content' => $content]],
            'outputConfig' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
            'betas' => ['server-side-fallback-2026-07-01'],
            'fallbacks' => 'default',
        ];
    }

    public function parse(string $json): ?Command
    {
        $data = json_decode($json, true);

        return is_array($data) && isset($data['intent']) ? Command::fromArray($data) : null;
    }

    public static function schema(): array
    {
        $nullable = fn (array $type) => ['anyOf' => [$type, ['type' => 'null']]];

        return [
            'type' => 'object',
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => Command::INTENTS],
                'subscriber' => $nullable(['type' => 'string']),
                'days' => $nullable(['type' => 'integer']),
                'amount' => $nullable(['type' => 'integer']),
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'quantity' => $nullable(['type' => 'integer']),
                            'unit_price' => $nullable(['type' => 'integer']),
                            'total' => $nullable(['type' => 'integer']),
                        ],
                        'required' => ['description', 'quantity', 'unit_price', 'total'],
                        'additionalProperties' => false,
                    ],
                ],
                'query' => $nullable(['type' => 'string', 'enum' => Command::QUERIES]),
                'question' => $nullable(['type' => 'string']),
            ],
            'required' => ['intent', 'subscriber', 'days', 'amount', 'items', 'query', 'question'],
            'additionalProperties' => false,
        ];
    }

    public static function configured(): bool
    {
        return filled(config('whatsapp.ai.api_key'));
    }
}
