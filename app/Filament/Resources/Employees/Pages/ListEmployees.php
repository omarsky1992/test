<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Actions\EmployeeActions;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\User;
use App\Services\EmployeeFinance;
use App\Support\Money;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    public function getSubheading(): ?string
    {
        $finance = app(EmployeeFinance::class);
        $custody = User::all()->sum(fn (User $u) => $finance->custodyBalance($u));
        $advances = (int) \App\Models\EmployeeAdvance::sum('balance');

        return 'مجموع العهد مع الموظفين: '.Money::format($custody).' · مجموع السلف المستحقة: '.Money::format($advances).' · الموظفون يُضافون من «المستخدمون والصلاحيات».';
    }

    protected function getHeaderActions(): array
    {
        return [EmployeeActions::handOver(), EmployeeActions::giveAdvance()];
    }
}
