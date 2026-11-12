<?php

namespace App\Filament\Resources\CreditApplications\Pages;

use App\Filament\Resources\CreditApplications\CreditApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditApplications extends ListRecords
{
    protected static string $resource = CreditApplicationResource::class;

    public function getSubheading(): ?string
    {
        return 'كل دين انسدّ من الرصيد المقدم القديم: متى سُجّل الرصيد، ومتى نزل على الدين، وكم يوم بينهما. «إرجاع الدين» يرجّعه مفتوحاً بقيد عكسي، والرصيد يصير تفعيلاً نقدياً.';
    }
}
