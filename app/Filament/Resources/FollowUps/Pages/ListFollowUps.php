<?php

namespace App\Filament\Resources\FollowUps\Pages;

use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFollowUps extends ListRecords
{
    protected static string $resource = FollowUpResource::class;

    public function getTabs(): array
    {
        $base = FollowUpResource::getEloquentQuery();

        return [
            'all' => Tab::make('الكل')->badge((clone $base)->count()),
            'expired' => Tab::make('منتهية غير مكتملة')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('ends_at', '<', now()))
                ->badge((clone $base)->where('ends_at', '<', now())->count())->badgeColor('danger'),
            'today' => Tab::make('تنتهي اليوم')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereBetween('ends_at', [now(), now()->endOfDay()]))
                ->badge((clone $base)->whereBetween('ends_at', [now(), now()->endOfDay()])->count())->badgeColor('warning'),
            'tomorrow' => Tab::make('تنتهي غداً')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereBetween('ends_at', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])),
            'promised' => Tab::make('وعود دفع اليوم')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('followUps', fn (Builder $f) => $f->whereBetween('promised_at', [now()->startOfDay(), now()->endOfDay()]))),
        ];
    }
}
