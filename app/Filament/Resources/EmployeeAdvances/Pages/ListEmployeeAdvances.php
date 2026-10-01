<?php

namespace App\Filament\Resources\EmployeeAdvances\Pages;

use App\Enums\AdvanceStatus;
use App\Filament\Actions\EmployeeActions;
use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEmployeeAdvances extends ListRecords
{
    protected static string $resource = EmployeeAdvanceResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('غير مسددة بالكامل')->modifyQueryUsing(fn (Builder $query) => $query->where('status', '<>', AdvanceStatus::Paid)),
            'all' => Tab::make('الكل'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [EmployeeActions::giveAdvance()];
    }
}
