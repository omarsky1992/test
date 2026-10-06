<?php

namespace App\Filament\Resources\CommandPatterns\Pages;

use App\Filament\Resources\CommandPatterns\CommandPatternResource;
use Filament\Resources\Pages\ManageRecords;

class ManageCommandPatterns extends ManageRecords
{
    protected static string $resource = CommandPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [CommandPatternResource::testAction(), CommandPatternResource::createAction()];
    }
}
