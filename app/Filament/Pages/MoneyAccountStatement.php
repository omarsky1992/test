<?php

namespace App\Filament\Pages;

use App\Enums\MoneyAccountKind;
use App\Models\MoneyAccount;
use App\Services\TreasuryService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * كشف الصندوق: every movement in and out of a cash box, wallet or the company balance, with its
 * source, date, who recorded it and the balance after it.
 */
class MoneyAccountStatement extends Page
{
    protected string $view = 'filament.pages.money-account-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'كشف الصندوق';

    protected static ?string $slug = 'box-statement';

    #[Url]
    public string $box = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->user()->can('money_accounts.manage') || auth()->user()->can('cards.view');
    }

    /** Who sees only the cards (cards.view without money_accounts.manage) is shown only the cards. */
    private static function visibleBoxes(): Builder
    {
        return MoneyAccount::query()->when(! auth()->user()->can('money_accounts.manage'),
            fn ($q) => $q->where('kind', MoneyAccountKind::Electronic));
    }

    public function mount(): void
    {
        $this->box = $this->box ?: (string) (self::visibleBoxes()->where('is_active', true)->orderByRaw("kind = 'cash' desc")->orderBy('id')->value('id') ?? '');
        $this->from = $this->from ?: CarbonImmutable::today()->startOfMonth()->toDateString();
    }

    protected function getViewData(): array
    {
        $boxes = self::visibleBoxes()->orderBy('kind')->orderBy('name')->get();
        $box = $boxes->firstWhere('id', (int) $this->box) ?? $boxes->first();
        $from = filled($this->from) ? CarbonImmutable::parse($this->from) : null;
        $to = filled($this->to) ? CarbonImmutable::parse($this->to) : null;

        return [
            'boxes' => $boxes->mapWithKeys(fn (MoneyAccount $m) => [$m->id => "{$m->name} ({$m->kind->getLabel()})"]),
            'account' => $box,
            'statement' => $box ? app(TreasuryService::class)->statement($box, $from, $to) : null,
            'periodLabel' => $from || $to ? ($from?->format('Y/m/d') ?? 'البداية').' – '.($to?->format('Y/m/d') ?? 'اليوم') : 'منذ البداية',
        ];
    }
}
