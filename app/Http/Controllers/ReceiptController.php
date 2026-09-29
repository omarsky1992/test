<?php

namespace App\Http\Controllers;

use App\Models\DebtTransfer;
use App\Models\Payment;
use App\Services\Audit;
use App\Services\PaymentService;
use App\Services\Settings;
use Illuminate\Contracts\View\View;

class ReceiptController extends Controller
{
    public function show(Payment $payment, Settings $settings, Audit $audit, PaymentService $payments): View
    {
        abort_unless(auth()->user()->can('receipts.print'), 403);

        $payment->load(['subscriber', 'account', 'moneyAccount', 'creator', 'voider', 'allocations.debt.activation']);
        $audit->log('receipt.viewed', $payment, subscriberId: $payment->subscriber_id);

        $openDebt = (int) $payment->account->debts()->whereIn('status', ['open', 'partial'])->sum('balance');

        return view('receipts.show', [
            'payment' => $payment,
            'company' => [
                'name' => $settings->get('company.name'),
                'address' => $settings->get('company.address'),
                'phone' => $settings->get('company.phone'),
            ],
            'transfers' => DebtTransfer::whereIn('debt_id', $payment->allocations->pluck('debt_id'))
                ->where('performed_at', '>=', $payment->received_at)->where('status', 'posted')->get(),
            'remaining' => $openDebt - $payments->creditBalance($payment->account),
        ]);
    }
}
