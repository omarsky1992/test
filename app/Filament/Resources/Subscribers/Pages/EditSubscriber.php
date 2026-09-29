<?php

namespace App\Filament\Resources\Subscribers\Pages;

use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Services\SubscriberService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSubscriber extends EditRecord
{
    protected static string $resource = SubscriberResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SubscriberService::class)->update($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
