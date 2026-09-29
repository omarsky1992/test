<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Services\SubscriberService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSubscriber extends CreateRecord
{
    protected static string $resource = SubscriberResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $account = $data['account'] ?? null;
        unset($data['account']);

        return app(SubscriberService::class)->create($data, filled($account['username'] ?? null) ? $account : null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
