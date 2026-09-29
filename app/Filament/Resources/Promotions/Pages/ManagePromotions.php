<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePromotions extends ManageRecords
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [PromotionResource::createAction()];
    }
}
