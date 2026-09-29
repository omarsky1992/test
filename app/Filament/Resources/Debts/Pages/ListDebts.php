<?php

namespace App\Filament\Resources\Debts\Pages;

use App\Enums\DebtBucket;
use App\Enums\DebtStatus;
use App\Filament\Resources\Debts\DebtResource;
use App\Models\Debt;
use App\Support\Money;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDebts extends ListRecords
{
    protected static string $resource = DebtResource::class;

    protected function getHeaderActions(): array
    {
        return [DebtResource::manualDebtAction()];
    }

    public function getTabs(): array
    {
        $open = fn (Builder $query) => $query->whereIn('status', [DebtStatus::Open, DebtStatus::Partial]);
        $total = fn (DebtBucket $bucket) => Money::format((int) Debt::where('bucket', $bucket)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])->sum('balance'), false);

        return [
            'secondary' => Tab::make('الديون الثانوية')->badge($total(DebtBucket::Secondary))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('bucket', DebtBucket::Secondary)),
            'primary' => Tab::make('الديون الأولية')->badge($total(DebtBucket::Primary))->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('bucket', DebtBucket::Primary)),
            'paid' => Tab::make('المسددة')->modifyQueryUsing(fn (Builder $query) => $query->where('status', DebtStatus::Paid)),
            'voided' => Tab::make('الملغاة')->modifyQueryUsing(fn (Builder $query) => $query->where('status', DebtStatus::Voided)),
            'all' => Tab::make('الكل'),
        ];
    }
}
