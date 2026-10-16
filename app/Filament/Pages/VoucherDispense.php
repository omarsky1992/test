<?php

namespace App\Filament\Pages;

use App\Exceptions\BusinessRuleException;
use App\Models\VoucherCard;
use App\Services\VoucherStock;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * «صرف الكروت»: one card per category with its own «إنشاء» button. A click takes one card from that
 * category only, shows its code with a big «نسخ» button, and the card is used from then on.
 */
class VoucherDispense extends Page
{
    protected string $view = 'filament.pages.voucher-dispense';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'صرف الكروت';

    protected static ?string $slug = 'card-dispense';

    /** @var array{id: int, code: string, type: string, denomination: int}|null */
    public ?array $issued = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        return auth()->user()->can('stock.dispense');
    }

    /** Called by a category's «إنشاء» button with a fresh key per click. */
    public function dispense(string $type, int $denomination, string $key): void
    {
        $this->error = null;
        try {
            $card = app(VoucherStock::class)->dispense($type, $denomination, Str::isUuid($key) ? $key : null);
        } catch (BusinessRuleException|AuthorizationException $e) {
            $this->issued = null;
            $this->error = (VoucherCard::TYPES[$type] ?? $type).' '.number_format($denomination).': '.$e->getMessage();

            return;
        }
        $this->issued = ['id' => $card->id, 'code' => $card->code, 'type' => $card->type, 'denomination' => (int) $card->denomination];
    }

    public function clear(): void
    {
        $this->issued = null;
        $this->error = null;
    }

    protected function getViewData(): array
    {
        return [
            'categories' => app(VoucherStock::class)->categories(),
            'canAdd' => auth()->user()->can('stock.add'),
            'canHistory' => auth()->user()->can('stock.history'),
        ];
    }
}
