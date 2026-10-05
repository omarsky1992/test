<?php

namespace App\Filament\Resources\WhatsappOutbox\Pages;

use App\Filament\Resources\WhatsappOutbox\WhatsappOutboxResource;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappOutbox extends ListRecords
{
    protected static string $resource = WhatsappOutboxResource::class;
}
