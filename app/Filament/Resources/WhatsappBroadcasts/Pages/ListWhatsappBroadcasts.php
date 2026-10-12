<?php

namespace App\Filament\Resources\WhatsappBroadcasts\Pages;

use App\Filament\Pages\WhatsappBroadcast;
use App\Filament\Resources\WhatsappBroadcasts\WhatsappBroadcastResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappBroadcasts extends ListRecords
{
    protected static string $resource = WhatsappBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('new')->label('رسالة جماعية جديدة')->icon('heroicon-o-megaphone')->url(WhatsappBroadcast::getUrl())];
    }
}
