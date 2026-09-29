<?php

namespace App\Filament\Resources\Activations\Pages;

use App\Filament\Actions\Operations;
use App\Filament\Resources\Activations\ActivationResource;
use Filament\Resources\Pages\ListRecords;

class ListActivations extends ListRecords
{
    protected static string $resource = ActivationResource::class;

    protected function getHeaderActions(): array
    {
        return [Operations::activate('newActivation')->label('تفعيل جديد')];
    }
}
