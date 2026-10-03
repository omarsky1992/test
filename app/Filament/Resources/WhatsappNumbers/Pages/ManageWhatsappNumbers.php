<?php

namespace App\Filament\Resources\WhatsappNumbers\Pages;

use App\Filament\Resources\WhatsappNumbers\WhatsappNumberResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWhatsappNumbers extends ManageRecords
{
    protected static string $resource = WhatsappNumberResource::class;

    protected function getHeaderActions(): array
    {
        return [WhatsappNumberResource::addAction()];
    }
}
