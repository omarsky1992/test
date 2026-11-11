<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Actions\Operations;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Account;
use App\Services\PaymentService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Operations::pay('newPayment')->label('قبض جديد'),
            // Shown to the admin only while some account still holds old advance credit.
            Action::make('clearCredits')->label('حذف الأرصدة المقدمة')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->visible(fn () => auth()->user()->isAdmin() && app(PaymentService::class)->creditAccounts()->isNotEmpty())
                ->modalHeading('حذف الأرصدة المقدمة القديمة')
                ->modalDescription(function () {
                    $credits = app(PaymentService::class)->creditAccounts();

                    return 'على '.$credits->count().' يوزر رصيد مقدم مجموعه '.Money::format($credits->sum()).'. يُحوَّل كله إلى «تفعيل نقدي» ويصير رصيدهم صفراً. '
                        .'الصناديق والعهد والبطاقات والديون لا تتغير، ولا يُحذف أي سند. من عليه دين يُسدَّد دينه من رصيده أولاً.';
                })
                ->schema([TextInput::make('confirm')->label('اكتب كلمة «حذف» للتأكيد')->required()->in(['حذف'])->validationMessages(['in' => 'اكتب كلمة حذف.'])])
                ->modalSubmitActionLabel('حذف الأرصدة المقدمة')
                ->action(function () {
                    $payments = app(PaymentService::class);
                    $credits = $payments->creditAccounts();
                    $cleared = 0;
                    foreach (Account::whereIn('id', $credits->keys())->get() as $account) {
                        $cleared += $payments->clearCredit($account, 'حذف الأرصدة المقدمة القديمة');
                    }
                    Notification::make()->success()->title('حُذفت الأرصدة المقدمة')
                        ->body(Money::format($cleared).' على '.$credits->count().' يوزر صارت تفعيلاً نقدياً.')->send();
                }),
        ];
    }
}
