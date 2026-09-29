<?php

namespace App\Support;

use BackedEnum;
use Filament\Support\Contracts\HasLabel;

class Options
{
    /**
     * [value => label] for a backed enum, so action forms keep plain string state.
     *
     * @param  class-string<BackedEnum&HasLabel>  $enum
     * @return array<string, string>
     */
    public static function of(string $enum): array
    {
        return collect($enum::cases())->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])->all();
    }
}
