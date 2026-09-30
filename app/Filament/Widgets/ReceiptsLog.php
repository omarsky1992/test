<?php

namespace App\Filament\Widgets;

use App\Enums\DocumentStatus;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Payment;
use App\Models\PaymentMethodType;
use App\Services\ReportService;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Receipts in the selected period, each with how it was paid (one or several methods).
 */
class ReceiptsLog extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => 'سجل الاستلام المالي — '.ReportService::periodRange($this->pageFilters)[2])
            ->query(function () {
                [$from, $to] = ReportService::periodRange($this->pageFilters);

                return Payment::query()->with(['subscriber', 'account', 'lines.method', 'lines.moneyAccount', 'creator'])
                    ->where('status', DocumentStatus::Posted)
                    ->whereBetween('received_at', [$from->startOfDay(), $to->endOfDay()])
                    ->latest('received_at');
            })
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('received_at')->label('الوقت')->dateTime('m/d H:i'),
                TextColumn::make('receipt_number')->label('السند')->weight('bold'),
                TextColumn::make('subscriber.full_name')->label('المشترك')
                    ->url(fn (Payment $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('methods')->label('طريقة الدفع')->badge()
                    ->state(fn (Payment $r) => $r->lines->map(fn ($l) => $l->method->name_ar.($r->lines->count() > 1 ? ' '.Money::format($l->amount, false) : ''))->all()),
                TextColumn::make('boxes')->label('استُلم في')
                    ->state(fn (Payment $r) => $r->lines->map(fn ($l) => $l->moneyAccount->name.($l->receiver_name ? " ({$l->receiver_name})" : ''))->unique()->join('، ')),
                TextColumn::make('amount')->label('المبلغ')->weight('bold')->formatStateUsing(fn ($state) => Money::format($state, false)),
                TextColumn::make('creator.name')->label('الموظف'),
            ])
            ->filters([
                SelectFilter::make('payment_method')->label('طريقة الدفع')
                    ->options(fn () => PaymentMethodType::orderBy('sort_order')->pluck('name_ar', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn ($query, $id) => $query->whereHas('lines', fn ($l) => $l->where('payment_method_id', $id)))),
            ])
            ->recordActions([Operations::receipt()]);
    }
}
