<?php

namespace App\Filament\Resources\SyncedRenewals\Pages;

use App\Filament\Resources\SyncedRenewals\SyncedRenewalResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncedRenewals extends ListRecords
{
    protected static string $resource = SyncedRenewalResource::class;

    public function getSubheading(): ?string
    {
        return 'كل تفعيل وتجديد اكتشفته المزامنة من موقع الشركة: شلون انفع بالموقع (كارت، محفظة الوكيل، محفظة المشترك) وشنو صار بيه (دين ثانوي، دين أولي، نقد، مناقلة، بدون دين). للمتابعة والتأكد من دقة المزامنة.';
    }
}
