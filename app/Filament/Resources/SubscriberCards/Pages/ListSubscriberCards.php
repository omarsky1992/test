<?php

namespace App\Filament\Resources\SubscriberCards\Pages;

use App\Filament\Resources\SubscriberCards\SubscriberCardResource;
use App\Support\SubscriberStatus;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSubscriberCards extends ListRecords
{
    protected static string $resource = SubscriberCardResource::class;

    protected ?string $heading = 'المشتركون';

    public function getTabs(): array
    {
        $counts = SubscriberStatus::counts();
        $colors = ['must_activate' => 'primary', 'expiring' => 'warning', 'expired' => 'danger', 'active_primary' => 'danger', 'expired_primary' => 'danger'];

        return collect(SubscriberStatus::FILTERS)->map(fn (string $label, string $key) => Tab::make($label)
            ->badge($counts[$key])
            ->badgeColor($colors[$key] ?? 'gray')
            ->modifyQueryUsing(fn (Builder $query) => SubscriberStatus::apply($query, $key)))->all();
    }
}
