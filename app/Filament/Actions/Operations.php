<?php

namespace App\Filament\Actions;

use App\Enums\ActivationKind;
use App\Enums\CompletionStatus;
use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DebtStatus;
use App\Enums\DocumentStatus;
use App\Enums\FollowUpChannel;
use App\Enums\FollowUpOutcome;
use App\Enums\MoneyAccountKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\Settlement;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\Activation;
use App\Models\Debt;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\PaymentMethodType;
use App\Models\Promotion;
use App\Models\ServicePlan;
use App\Services\ActivationService;
use App\Services\DebtService;
use App\Services\PaymentService;
use App\Services\Pricing;
use App\Services\Settings;
use App\Services\SubscriberService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use App\Support\Options;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The day-to-day operations (activate, collect, transfer, void…) as reusable Filament actions,
 * so the same form appears on the subscriber page, the lists and the dashboard.
 */
class Operations
{
    public static function activate(string $name = 'activate'): Action
    {
        return Action::make($name)
            ->label('تفعيل')
            ->icon(Heroicon::OutlinedBolt)
            ->color('primary')
            ->modalHeading('تفعيل جديد')
            ->modalSubmitActionLabel('حفظ التفعيل')
            ->modalWidth('3xl')
            ->authorize('activations.create')
            ->fillForm(fn (?Model $record) => [
                'account_id' => self::accountOf($record)?->id,
                'plan_id' => self::accountOf($record)?->current_plan_id ?? ServicePlan::where('is_active', true)->orderBy('sort_order')->value('id'),
                'kind' => ActivationKind::Partial7->value,
                'settlement' => Settlement::Debt->value,
                'starts_at' => now()->format('Y-m-d H:i'),
            ])
            ->schema(fn (?Model $record) => [
                self::accountField()->hidden(self::accountOf($record) !== null),
                Grid::make(2)->schema([
                    Select::make('plan_id')
                        ->label('الفئة')
                        ->options(fn () => ServicePlan::where('is_active', true)->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (ServicePlan $p) => [$p->id => "{$p->name_ar} — ".Money::format($p->price)]))
                        ->required()
                        ->live(),
                    ToggleButtons::make('kind')
                        ->label('نوع التفعيل')
                        ->options(Options::of(ActivationKind::class))
                        ->inline()
                        ->required()
                        ->live(),
                    Select::make('promotion_id')
                        ->label('العرض')
                        ->placeholder('بدون عرض')
                        ->options(fn (Get $get) => self::promotionOptions($get('account_id'), $get('plan_id')))
                        ->live(),
                    DateTimePicker::make('starts_at')
                        ->label('وقت بداية التفعيل الفعلي')
                        ->seconds(false)
                        ->required()
                        ->live()
                        ->helperText('الافتراضي الآن. إذا وُجد اشتراك فعّال يبدأ التفعيل بعد انتهائه.')
                        ->disabled(fn () => ! auth()->user()->can('activations.edit_start')),
                    ToggleButtons::make('settlement')
                        ->label('التسوية')
                        ->options([Settlement::Debt->value => 'آجل (دين أولي)', Settlement::Paid->value => 'مدفوع الآن', Settlement::Credit->value => 'من الرصيد المقدم'])
                        ->inline()
                        ->visible(fn (Get $get) => $get('kind') === ActivationKind::Full30->value)
                        ->live(),
                    Select::make('money_account_id')
                        ->label('استُلم في')
                        ->options(fn () => self::receivingBoxes())
                        ->visible(fn (Get $get) => $get('kind') === ActivationKind::Full30->value && $get('settlement') === Settlement::Paid->value)
                        ->required(fn (Get $get) => $get('settlement') === Settlement::Paid->value),
                    TextInput::make('override_price')
                        ->label('سعر يدوي (اختياري)')
                        ->integer()
                        ->minValue(0)
                        ->suffix('د.ع')
                        ->visible(fn () => auth()->user()->can('activations.override_price'))
                        ->live(onBlur: true),
                    TextInput::make('external_ref')->label('مرجع العملية على موقع الشركة')->maxLength(100),
                ]),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
                Text::make(fn (Get $get) => self::activationPreview($get))->color('gray'),
            ])
            ->action(function (array $data, Action $action, ?Model $record) {
                self::guard($action, function () use ($data, $record) {
                    $account = self::accountOf($record) ?? Account::findOrFail($data['account_id']);
                    $kind = ActivationKind::from($data['kind']);
                    $activation = app(ActivationService::class)->activate(
                        account: $account,
                        plan: ServicePlan::findOrFail($data['plan_id']),
                        kind: $kind,
                        requestedStart: filled($data['starts_at'] ?? null) ? CarbonImmutable::parse($data['starts_at']) : null,
                        promotion: filled($data['promotion_id'] ?? null) ? Promotion::find($data['promotion_id']) : null,
                        settlement: $kind === ActivationKind::Partial7 ? Settlement::Debt : Settlement::from($data['settlement'] ?? 'debt'),
                        overridePrice: filled($data['override_price'] ?? null) ? (int) $data['override_price'] : null,
                        moneyAccount: filled($data['money_account_id'] ?? null) ? MoneyAccount::find($data['money_account_id']) : null,
                        externalRef: $data['external_ref'] ?? null,
                        notes: $data['notes'] ?? null,
                    );
                    Notification::make()
                        ->success()
                        ->title("تم التفعيل {$activation->number}")
                        ->body('من '.self::dt($activation->starts_at).' حتى '.self::dt($activation->ends_at).'. لا تنسَ التفعيل على موقع الشركة.')
                        ->persistent()
                        ->send();
                });
            });
    }

    public static function pay(string $name = 'pay'): Action
    {
        return Action::make($name)
            ->label('قبض')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading('تسجيل قبض')
            ->modalSubmitActionLabel('حفظ وإصدار السند')
            ->modalWidth('3xl')
            ->authorize('payments.create')
            ->fillForm(fn (?Model $record) => [
                'account_id' => self::accountOf($record)?->id,
                'payment_type' => PaymentType::DebtPayment->value,
                'lines' => [self::defaultLine($record instanceof Debt ? $record->balance : null)],
                'debt_ids' => $record instanceof Debt ? [$record->id] : [],
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->schema(fn (?Model $record) => [
                Hidden::make('idempotency_key'),
                self::accountField()->hidden(self::accountOf($record) !== null),
                Grid::make(2)->schema([
                    ToggleButtons::make('payment_type')->label('نوع القبض')->options(Options::of(PaymentType::class))->inline()->required()->live(),
                    DateTimePicker::make('received_at')
                        ->label('وقت القبض')
                        ->seconds(false)
                        ->placeholder('الآن')
                        ->visible(fn () => auth()->user()->can('payments.backdate')),
                ]),
                Repeater::make('lines')
                    ->label('طرق الدفع')
                    ->helperText('يمكن تقسيم المبلغ على أكثر من طريقة، مثلاً جزء نقدي وجزء زين كاش.')
                    ->addActionLabel('إضافة طريقة دفع')
                    ->minItems(1)
                    ->defaultItems(1)
                    ->reorderable(false)
                    ->live()
                    ->columns(4)
                    ->schema([
                        Select::make('method_id')
                            ->label('الطريقة')
                            ->options(fn () => self::methodOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                $method = PaymentMethodType::find($state);
                                $set('money_account_id', $method?->money_account_id ?? self::defaultBox($method?->category ?? PaymentMethod::Cash));
                            }),
                        Select::make('money_account_id')
                            ->label('استُلم في')
                            ->options(fn () => self::receivingBoxes())
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => $set('receiver', MoneyAccount::find($state)?->holder_name)),
                        TextInput::make('amount')->label('المبلغ')->integer()->minValue(1)->suffix('د.ع')->required()->live(onBlur: true),
                        TextInput::make('receiver')
                            ->label('اسم المستلم')
                            ->visible(fn (Get $get) => (bool) PaymentMethodType::find($get('method_id'))?->requires_receiver)
                            ->required(fn (Get $get) => (bool) PaymentMethodType::find($get('method_id'))?->requires_receiver),
                        TextInput::make('reference')
                            ->label('رقم العملية / الحوالة')
                            ->required(fn (Get $get) => (bool) PaymentMethodType::find($get('method_id'))?->requires_reference)
                            ->columnSpan(2),
                    ]),
                CheckboxList::make('debt_ids')
                    ->label('الديون المفتوحة')
                    ->helperText('بدون اختيار يُسدَّد الأقدم أولاً، والزيادة تصير رصيداً مقدماً.')
                    ->options(fn (Get $get) => self::openDebtOptions($get('account_id')))
                    ->visible(fn (Get $get) => $get('payment_type') === PaymentType::DebtPayment->value)
                    ->live(),
                Toggle::make('transfer_remainder')
                    ->label('تسديد جزئي لدين 7 أيام: انقل الباقي إلى الديون الأولية وأضف 23 يوماً')
                    ->visible(fn (Get $get) => $get('payment_type') === PaymentType::DebtPayment->value && self::hasPendingSecondary($get('account_id'))),
                Textarea::make('notes')->label('ملاحظات')->rows(2),
                Text::make(fn (Get $get) => self::paymentPreview($get))->color('gray'),
            ])
            ->action(function (array $data, Action $action, ?Model $record) {
                self::guard($action, function () use ($data, $record) {
                    $account = self::accountOf($record) ?? Account::findOrFail($data['account_id']);
                    $payment = app(PaymentService::class)->record(
                        account: $account,
                        type: PaymentType::from($data['payment_type']),
                        debtIds: array_map('intval', $data['debt_ids'] ?? []) ?: null,
                        receivedAt: filled($data['received_at'] ?? null) ? CarbonImmutable::parse($data['received_at']) : null,
                        notes: $data['notes'] ?? null,
                        transferRemainder: (bool) ($data['transfer_remainder'] ?? false),
                        idempotencyKey: $data['idempotency_key'] ?? null,
                        lines: array_map(fn (array $l) => [
                            'method' => (int) $l['method_id'],
                            'money_account' => (int) $l['money_account_id'],
                            'amount' => (int) $l['amount'],
                            'receiver' => $l['receiver'] ?? null,
                            'reference' => $l['reference'] ?? null,
                        ], array_values($data['lines'] ?? [])),
                    );
                    Notification::make()
                        ->success()
                        ->title("سند القبض {$payment->receipt_number}")
                        ->body(Money::format($payment->amount))
                        ->actions([Action::make('receipt')->label('عرض السند')->url(route('receipts.show', $payment))->openUrlInNewTab()])
                        ->persistent()
                        ->send();
                });
            });
    }

    /**
     * @return array<int, string>
     */
    private static function methodOptions(): array
    {
        return PaymentMethodType::where('is_active', true)->orderBy('sort_order')->pluck('name_ar', 'id')->all();
    }

    /**
     * @return array{method_id: ?int, money_account_id: ?int, amount: ?int, receiver: ?string, reference: null}
     */
    private static function defaultLine(?int $amount): array
    {
        $method = PaymentMethodType::where('is_active', true)->orderBy('sort_order')->first();

        return [
            'method_id' => $method?->id,
            'money_account_id' => $method?->money_account_id ?? self::defaultBox($method?->category ?? PaymentMethod::Cash),
            'amount' => $amount,
            'receiver' => null,
            'reference' => null,
        ];
    }

    public static function transfer(): Action
    {
        return Action::make('transfer')
            ->label('مناقلة')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('info')
            ->authorize('transfers.create')
            ->visible(fn (Model $record) => self::secondaryDebtOf($record) !== null)
            ->modalHeading('مناقلة إلى الديون الأولية')
            ->modalDescription(function (Model $record) {
                $debt = self::secondaryDebtOf($record);
                $days = $debt?->activation?->completion_status === CompletionStatus::Pending ? app(Settings::class)->extensionDays() : 0;

                return 'سيُنقل '.Money::format($debt?->balance).' من الديون الثانوية إلى الأولية'.($days ? " وتُضاف {$days} يوماً. نفّذ التمديد على موقع الشركة." : '.');
            })
            ->schema([Textarea::make('reason')->label('السبب / ملاحظة')->rows(2)])
            ->modalSubmitActionLabel('تنفيذ المناقلة')
            ->action(function (array $data, Action $action, Model $record) {
                self::guard($action, function () use ($data, $record) {
                    $transfer = app(DebtService::class)->transfer(self::secondaryDebtOf($record), $data['reason'] ?? null);
                    Notification::make()->success()->title("تمت المناقلة {$transfer->number}")
                        ->body($transfer->days_added ? "أُضيفت {$transfer->days_added} يوماً." : null)->send();
                });
            });
    }

    public static function followUp(): Action
    {
        return Action::make('followUp')
            ->label('نتيجة اتصال')
            ->icon(Heroicon::OutlinedPhone)
            ->color('gray')
            ->authorize('follow_ups.create')
            ->modalHeading('تسجيل نتيجة الاتصال')
            ->fillForm(['channel' => FollowUpChannel::Call->value])
            ->schema([
                ToggleButtons::make('channel')->label('الوسيلة')->options(Options::of(FollowUpChannel::class))->inline()->required(),
                ToggleButtons::make('outcome')->label('النتيجة')->options(Options::of(FollowUpOutcome::class))->inline()->required()->live(),
                Grid::make(2)->schema([
                    DateTimePicker::make('promised_at')->label('موعد الدفع الموعود')->seconds(false)
                        ->visible(fn (Get $get) => $get('outcome') === FollowUpOutcome::PromisedToPay->value),
                    DateTimePicker::make('next_follow_up_at')->label('موعد الاتصال التالي')->seconds(false),
                ]),
                Textarea::make('note')->label('ملاحظة')->rows(2),
            ])
            ->action(function (array $data, Model $record) {
                $activation = $record instanceof Activation ? $record : null;
                app(SubscriberService::class)->logFollowUp(
                    account: self::accountOf($record),
                    channel: FollowUpChannel::from($data['channel']),
                    outcome: FollowUpOutcome::from($data['outcome']),
                    promisedAt: filled($data['promised_at'] ?? null) ? CarbonImmutable::parse($data['promised_at']) : null,
                    nextAt: filled($data['next_follow_up_at'] ?? null) ? CarbonImmutable::parse($data['next_follow_up_at']) : null,
                    note: $data['note'] ?? null,
                    activation: $activation,
                );
                Notification::make()->success()->title('تم حفظ نتيجة الاتصال')->send();
            });
    }

    public static function editStart(): Action
    {
        return Action::make('editStart')
            ->label('تعديل وقت البداية')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->authorize('activations.edit_start')
            ->visible(fn (Activation $record) => $record->status === DocumentStatus::Posted)
            ->fillForm(fn (Activation $record) => ['starts_at' => $record->starts_at->format('Y-m-d H:i')])
            ->schema([
                DateTimePicker::make('starts_at')->label('وقت البداية الصحيح')->seconds(false)->required(),
                Textarea::make('reason')->label('سبب التعديل')->required()->rows(2),
            ])
            ->action(function (array $data, Action $action, Activation $record) {
                self::guard($action, function () use ($data, $record) {
                    $activation = app(ActivationService::class)->editStart($record, CarbonImmutable::parse($data['starts_at']), $data['reason']);
                    Notification::make()->success()->title('تم التعديل')->body('ينتهي الآن في '.self::dt($activation->ends_at))->send();
                });
            });
    }

    public static function voidActivation(): Action
    {
        return self::voidAction('voidActivation', 'إلغاء التفعيل (تصحيح)', 'activations.void',
            'سيُلغى التفعيل ودينه ويُعاد السعر إلى رصيد الشركة. لا يمكن التراجع.',
            fn (Activation $record) => $record->status === DocumentStatus::Posted,
            fn (Activation $record, string $reason) => app(ActivationService::class)->void($record, $reason));
    }

    public static function voidDebt(): Action
    {
        return self::voidAction('voidDebt', 'حذف الدين', 'debts.void',
            'يُلغى الدين بقيد عكسي ويبقى في سجل العمليات. إذا كان من تفعيل يُلغى التفعيل معه.',
            fn (Debt $record) => in_array($record->status, [DebtStatus::Open, DebtStatus::Partial], true) && $record->source !== DebtSource::DeviceSale,
            fn (Debt $record, string $reason) => app(DebtService::class)->void($record, $reason));
    }

    public static function voidPayment(): Action
    {
        return self::voidAction('voidPayment', 'إلغاء السند', 'payments.void',
            'يُلغى السند بقيد عكسي ويعود الدين مفتوحاً. الأيام المضافة للتفعيل تبقى.',
            fn (Payment $record) => $record->status === DocumentStatus::Posted,
            fn (Payment $record, string $reason) => app(PaymentService::class)->void($record, $reason));
    }

    public static function revealSecret(): Action
    {
        return Action::make('revealSecret')
            ->label('إظهار الباسورد')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize('accounts.view_secret')
            ->modalHeading(fn (Account $record) => "باسورد {$record->username}")
            ->modalContent(fn (Account $record) => new HtmlString(
                '<p dir="ltr" style="font-size:1.5rem;font-weight:700;text-align:center;letter-spacing:.05em">'
                .e(app(SubscriberService::class)->revealSecret($record) ?? '—').'</p>'
                .'<p style="text-align:center;color:#78716c;font-size:.8rem">تم تسجيل هذا العرض في سجل العمليات</p>'
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('إغلاق');
    }

    public static function receipt(): Action
    {
        return Action::make('receipt')
            ->label('السند')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->url(fn (Payment $record) => route('receipts.show', $record))
            ->openUrlInNewTab();
    }

    public static function accountField(): Select
    {
        return Select::make('account_id')
            ->label('الحساب')
            ->searchable()
            ->required()
            ->live()
            ->getSearchResultsUsing(fn (string $search) => Account::query()
                ->with('subscriber')
                ->where(fn (Builder $query) => $query->where('username', 'ilike', '%'.addcslashes($search, '%_').'%')
                    ->orWhereHas('subscriber', fn (Builder $s) => $s->search($search)))
                ->limit(20)->get()
                ->mapWithKeys(fn (Account $a) => [$a->id => self::accountLabel($a)]))
            ->getOptionLabelUsing(fn ($value) => ($a = Account::with('subscriber')->find($value)) ? self::accountLabel($a) : null);
    }

    public static function dt(?\DateTimeInterface $at): string
    {
        return $at ? $at->format('Y/m/d H:i') : '—';
    }

    private static function voidAction(string $name, string $label, string $permission, string $description, Closure $visible, Closure $run): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize($permission)
            ->visible($visible)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->schema([Textarea::make('reason')->label('السبب')->required()->rows(2)])
            ->action(function (array $data, Action $action, Model $record) use ($run) {
                self::guard($action, function () use ($data, $record, $run) {
                    $run($record, $data['reason']);
                    Notification::make()->success()->title('تم الإلغاء')->send();
                });
            });
    }

    private static function guard(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $action->halt();
        }
    }

    private static function accountOf(?Model $record): ?Account
    {
        return match (true) {
            $record instanceof Account => $record,
            $record instanceof Activation, $record instanceof Debt, $record instanceof Payment => $record->account,
            $record instanceof \App\Models\Subscriber && $record->accounts()->count() === 1 => $record->accounts()->first(),
            default => null,
        };
    }

    private static function secondaryDebtOf(Model $record): ?Debt
    {
        $debt = match (true) {
            $record instanceof Debt => $record,
            $record instanceof Activation => $record->debt,
            default => null,
        };

        return $debt && $debt->bucket === DebtBucket::Secondary && in_array($debt->status, [DebtStatus::Open, DebtStatus::Partial], true) ? $debt : null;
    }

    private static function accountLabel(Account $account): string
    {
        return "{$account->subscriber->full_name} — {$account->username}".($account->location_label ? " ({$account->location_label})" : '');
    }

    private static function promotionOptions(?int $accountId, ?int $planId): array
    {
        $account = $accountId ? Account::find($accountId) : null;
        $plan = $planId ? ServicePlan::find($planId) : null;
        if (! $account || ! $plan) {
            return [];
        }

        return app(Pricing::class)->eligiblePromotions($account, $plan, now())->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    private static function receivingBoxes(?PaymentMethod $method = null): array
    {
        // Non-cash methods (wallets, cards, bank transfers) land in electronic boxes.
        $kinds = match ($method) {
            PaymentMethod::Cash => [MoneyAccountKind::Cash],
            null => [MoneyAccountKind::Cash, MoneyAccountKind::Electronic],
            default => [MoneyAccountKind::Electronic],
        };

        return MoneyAccount::where('is_active', true)->whereIn('kind', $kinds)->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function defaultBox(PaymentMethod $method): ?int
    {
        return array_key_first(self::receivingBoxes($method));
    }

    private static function openDebtOptions(?int $accountId): array
    {
        if (! $accountId) {
            return [];
        }

        return Debt::where('account_id', $accountId)->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])
            ->orderBy('debt_date')->get()
            ->mapWithKeys(fn (Debt $d) => [$d->id => "{$d->number} · {$d->bucket->getLabel()} · المتبقي ".Money::format($d->balance)])
            ->all();
    }

    private static function hasPendingSecondary(?int $accountId): bool
    {
        return $accountId && Debt::where('account_id', $accountId)->where('bucket', DebtBucket::Secondary)
            ->whereIn('status', [DebtStatus::Open, DebtStatus::Partial])
            ->whereHas('activation', fn (Builder $query) => $query->where('completion_status', CompletionStatus::Pending))
            ->exists();
    }

    private static function activationPreview(Get $get): ?HtmlString
    {
        $account = $get('account_id') ? Account::find($get('account_id')) : null;
        $plan = $get('plan_id') ? ServicePlan::find($get('plan_id')) : null;
        $kind = ActivationKind::tryFrom((string) $get('kind'));
        if (! $account || ! $plan || ! $kind) {
            return null;
        }

        try {
            $price = app(Pricing::class)->quote($plan, $get('promotion_id') ? Promotion::find($get('promotion_id')) : null,
                filled($get('override_price')) ? (int) $get('override_price') : null);
        } catch (BusinessRuleException $e) {
            return new HtmlString(e($e->getMessage()));
        }
        $settings = app(Settings::class);
        $requested = filled($get('starts_at')) ? CarbonImmutable::parse($get('starts_at')) : CarbonImmutable::now();
        $lastEnd = app(ActivationService::class)->lastEnd($account);
        $start = $lastEnd && $lastEnd->greaterThan($requested) ? $lastEnd : $requested;
        $days = $kind === ActivationKind::Partial7 ? $settings->partialDays() : $settings->fullDays();
        $bucket = $kind === ActivationKind::Partial7 ? 'ثانوي' : 'أولي';

        $lines = [
            'البداية: <b>'.self::dt($start).'</b> — النهاية: <b>'.self::dt($start->addDays($days)).'</b>',
            'السعر: <b>'.Money::format($price['final_price']).'</b>'.($price['discount_amount'] ? ' (خصم '.Money::format($price['discount_amount']).')' : ''),
            "الدين الناتج: <b>{$bucket}</b>",
        ];
        if ($lastEnd && $lastEnd->greaterThan($requested)) {
            array_unshift($lines, '⚠️ يوجد اشتراك فعّال حتى '.self::dt($lastEnd).'، والتفعيل الجديد يبدأ بعده.');
        }

        return new HtmlString(implode('<br>', $lines));
    }

    private static function paymentPreview(Get $get): ?HtmlString
    {
        $account = $get('account_id') ? Account::find($get('account_id')) : null;
        $amount = (int) collect($get('lines') ?? [])->sum(fn ($l) => (int) ($l['amount'] ?? 0));
        if (! $account || $amount <= 0) {
            return null;
        }
        if ($get('payment_type') === PaymentType::Advance->value) {
            return new HtmlString('يُضاف <b>'.Money::format($amount).'</b> رصيداً مقدماً على الحساب.');
        }

        try {
            $plan = app(PaymentService::class)->allocationPlan($account, $amount, array_map('intval', $get('debt_ids') ?? []) ?: null);
        } catch (BusinessRuleException $e) {
            return new HtmlString(e($e->getMessage()));
        }
        $lines = array_map(function (array $row) {
            $debt = $row['debt'];
            $note = $row['amount'] >= $debt->balance && $debt->activation?->completion_status === CompletionStatus::Pending
                ? ' ← <b>يُكمل التفعيل +'.app(Settings::class)->extensionDays().' يوماً</b>' : '';

            return "{$debt->number}: ".Money::format($row['amount']).$note;
        }, $plan);
        $credit = $amount - array_sum(array_column($plan, 'amount'));
        if ($credit > 0) {
            $lines[] = 'رصيد مقدم: '.Money::format($credit);
        }

        return new HtmlString('<b>معاينة التوزيع</b><br>'.implode('<br>', $lines));
    }
}
