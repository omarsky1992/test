<?php

namespace App\Filament\Resources\HandoverRequests\Pages;

use App\Filament\Resources\HandoverRequests\HandoverRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListHandoverRequests extends ListRecords
{
    protected static string $resource = HandoverRequestResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('بانتظار التأكيد')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'all' => Tab::make('الكل'),
        ];
    }
}
