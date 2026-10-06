<?php

namespace App\WhatsApp;

use App\Models\CommandPattern;
use App\Services\Settings;
use Illuminate\Support\Collection;

/**
 * The admin's own phrasings (صيغ الأوامر), tried before the built-in rules. A phrasing is plain
 * words with blanks: «تفعيل {الاسم} {الأيام}», «قبض {الاسم} {المبلغ}». The words must match (after the
 * same Arabic normalizing as everything else); each blank must hold a valid value.
 */
class CustomPatterns
{
    private const MARKERS = ['{الاسم}' => 'n', '{الأيام}' => 'd', '{الايام}' => 'd', '{المبلغ}' => 'a', '{المادة}' => 'i'];

    /** @var Collection<int, CommandPattern>|null */
    private ?Collection $patterns = null;

    public function match(string $text): ?Command
    {
        $words = self::words_of(RuleInterpreter::clean($text));
        if ($words === []) {
            return null;
        }
        foreach ($this->patterns() as $pattern) {
            $tokens = self::tokens($pattern->pattern);
            if ($tokens !== [] && ($values = self::walk($tokens, 0, $words, 0, [], $pattern)) !== null
                && ($command = self::command($pattern, $values))) {
                return $command;
            }
        }

        return null;
    }

    /**
     * The phrasing as words and blanks: «تفعيل {الاسم} {الأيام}» → ['تفعيل', '§N', '§D'].
     *
     * @return array<int, string>
     */
    public static function tokens(string $pattern): array
    {
        $marked = strtr($pattern, array_map(fn ($c) => " xx{$c}xx ", self::MARKERS));

        return array_map(fn ($w) => preg_match('/^xx([ndai])xx$/i', $w, $m) ? '§'.strtoupper($m[1]) : $w,
            self::words_of(RuleInterpreter::clean($marked)));
    }

    /**
     * Matches words against tokens; each blank takes as few words as give a valid value, so
     * «تفعيل محمد رمضان سبعة ايام» gives الاسم = «محمد رمضان» and الأيام = «سبعة ايام».
     *
     * @return array<string, string>|null
     */
    private static function walk(array $tokens, int $p, array $words, int $w, array $values, CommandPattern $pattern): ?array
    {
        if ($p === count($tokens)) {
            return $w === count($words) ? $values : null;
        }
        $token = $tokens[$p];
        if (! str_starts_with($token, '§')) {
            return ($words[$w] ?? null) === $token ? self::walk($tokens, $p + 1, $words, $w + 1, $values, $pattern) : null;
        }
        $blank = substr($token, 2);
        $after = count($tokens) - $p - 1; // every later token needs at least one word
        for ($n = 1; $w + $n <= count($words) - $after; $n++) {
            $value = implode(' ', array_slice($words, $w, $n));
            if (self::valid($blank, $value, $pattern)
                && ($found = self::walk($tokens, $p + 1, $words, $w + $n, $values + [$blank => $value], $pattern)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function valid(string $blank, string $value, CommandPattern $pattern): bool
    {
        return match ($blank) {
            'D' => self::days($value) !== null,
            'A' => RuleInterpreter::amount($value) !== null,
            // A name never holds the word «دين»: «تفعيل محمد دين اولي» is not the activation of «محمد دين اولي».
            'N' => ! preg_match('/(^|\s)(دين|ديون|بدين)(\s|$)/u', $value),
            default => true,
        };
    }

    /** @return array<int, string> */
    private static function words_of(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  array<string, string>  $values  blank letter => captured text
     */
    private static function command(CommandPattern $pattern, array $values): ?Command
    {
        $name = $values['N'] ?? null;
        $amount = null;
        if (isset($values['A'])) {
            $amount = RuleInterpreter::amount($values['A']);
            if ($amount === null) {
                return null;
            }
            if ($pattern->amount_in_thousands && $amount < 1000) {
                $amount *= 1000;
            }
        }

        if (str_starts_with($pattern->action, 'query:')) {
            return new Command('query', query: substr($pattern->action, 6));
        }

        return match ($pattern->action) {
            'activate' => ($days = self::days($values['D'] ?? null) ?? $pattern->default_days) && $name
                ? new Command('activate', subscriber: $name, days: $days) : null,
            'activate_primary' => $name ? new Command('add_debt', subscriber: $name, days: self::days($values['D'] ?? null) ?? $pattern->default_days
                ?? app(Settings::class)->fullDays(), amount: $amount, bucket: 'primary') : null,
            'payment' => $name && $amount ? new Command('payment', subscriber: $name, amount: $amount) : null,
            'add_debt_primary' => $name && $amount ? new Command('add_debt', subscriber: $name, amount: $amount, bucket: 'primary') : null,
            'add_debt_secondary' => $name && $amount ? new Command('add_debt', subscriber: $name, amount: $amount, bucket: 'secondary') : null,
            'transfer' => $name ? new Command('transfer', subscriber: $name) : null,
            'void_debt' => $name ? new Command('void_debt', subscriber: $name, amount: $amount) : null,
            'subscriber_debt' => $name ? new Command('query', subscriber: $name, query: 'subscriber_debt') : null,
            'purchase', 'sale' => ($items = self::items($values['I'] ?? '', $amount)) ? new Command($pattern->action, items: $items) : null,
            default => null,
        };
    }

    /** «7», «سبعة», «7 ايام», «شهر» → days. */
    private static function days(?string $text): ?int
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        $n = ctype_digit($text) ? (int) $text : (RuleInterpreter::days($text) ?? RuleInterpreter::wordsToNumber($text));

        return $n !== null && $n > 0 && $n <= 365 ? $n : null;
    }

    private static function items(string $text, ?int $amount): ?array
    {
        if ($amount !== null && trim($text) !== '') {
            return [['description' => trim($text), 'quantity' => null, 'unit_price' => null, 'total' => $amount]];
        }

        return RuleInterpreter::items($text);
    }

    /** @return Collection<int, CommandPattern> */
    private function patterns(): Collection
    {
        return $this->patterns ??= CommandPattern::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** The literal words of the active phrasings, as a hint for speech-to-text. */
    public static function words(): string
    {
        return CommandPattern::where('is_active', true)->pluck('pattern')
            ->map(fn ($p) => trim(preg_replace('/\{[^}]+\}/u', '', $p)))->filter()->unique()->implode('، ');
    }
}
