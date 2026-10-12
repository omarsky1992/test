<?php

namespace App\Filament\Resources\Blacklist\Pages;

use App\Filament\Resources\Blacklist\BlacklistResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBlacklist extends ManageRecords
{
    protected static string $resource = BlacklistResource::class;

    public function getSubheading(): ?string
    {
        return 'للمتابعة الداخلية فقط: الإضافة لا تغيّر الاشتراك أو الديون ولا تمنع أي خدمة.';
    }

    protected function getHeaderActions(): array
    {
        return [BlacklistResource::addAction()];
    }
}
