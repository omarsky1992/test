<?php

namespace App\Filament\Resources\ServicePlans\Pages;

use App\Filament\Resources\ServicePlans\ServicePlanResource;
use Filament\Resources\Pages\ManageRecords;

class ManageServicePlans extends ManageRecords
{
    protected static string $resource = ServicePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [ServicePlanResource::createAction()];
    }
}
