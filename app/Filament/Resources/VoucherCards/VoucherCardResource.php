<?php

namespace App\Filament\Resources\VoucherCards;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\VoucherCards\Pages\ListVoucherCards;
use App\Models\VoucherCard;
use App\Services\VoucherStock;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «مخزون الكارتات»: internet and sports-channel cards, searchable by code, filtered by type,
 * denomination and status, activated once each.
 */
class VoucherCardResource extends Resource
{
    protected static ?string $model = VoucherCard::class;

    protected static ?string $slug = 'card-stock';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'كارت';

    protected static ?string $pluralModelLabel = 'مخزون الكارتات';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('stock.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['adder', 'activator']))
            ->defaultSort('id', 'desc')
            ->searchPlaceholder('رقم الكارت')
            ->columns([
                TextColumn::make('type')->label('النوع')->badge()->formatStateUsing(fn (string $state) => VoucherCard::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'internet' ? 'info' : 'warning'),
                TextColumn::make('denomination')->label('الفئة')->formatStateUsing(fn ($state) => Money::format((int) $state))->sortable(),
                TextColumn::make('code')->label('رقم الكارت')->extraAttributes(['dir' => 'ltr'])->fontFamily('mono')
                    ->formatStateUsing(fn (VoucherCard $r) => $r->visibleCode())
                    ->searchable(query: function (Builder $query, string $search) {
                        $code = VoucherStock::normalize($search);

                        // Whole codes are searched by anyone; part of a code only by who may see codes.
                        return auth()->user()->can('stock.view_codes')
                            ? $query->where('code', 'ilike', '%'.addcslashes($code, '%_\\').'%')
                            : $query->where('code', $code);
                    }),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn (string $state) => VoucherCard::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => ['available' => 'success', 'activated' => 'gray', 'void' => 'danger'][$state] ?? null)
                    ->description(fn (VoucherCard $r) => match ($r->status) {
                        'activated' => 'فعّله '.($r->activator?->name ?? '—').' '.$r->activated_at?->setTimezone(config('app.timezone'))->format('Y/m/d H:i'),
                        'void' => $r->void_reason,
                        default => null,
                    }),
                TextColumn::make('created_at')->label('أُضيف')->dateTime('Y/m/d')->sortable()
                    ->description(fn (VoucherCard $r) => $r->adder?->name),
                TextColumn::make('notes')->label('ملاحظات')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('النوع')->options(VoucherCard::TYPES),
                SelectFilter::make('denomination')->label('الفئة')
                    ->options(fn () => VoucherCard::query()->distinct()->orderBy('denomination')->pluck('denomination')
                        ->mapWithKeys(fn ($d) => [$d => Money::format((int) $d)])->all()),
                SelectFilter::make('status')->label('الحالة')->options(VoucherCard::STATUSES),
            ])
            ->recordActions([
                Action::make('activate')->label('تفعيل')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                    ->visible(fn (VoucherCard $r) => $r->status === 'available' && auth()->user()->can('stock.activate'))
                    ->requiresConfirmation()->modalHeading(fn (VoucherCard $r) => 'تفعيل كارت '.VoucherCard::TYPES[$r->type].' '.Money::format((int) $r->denomination).'؟')
                    ->action(fn (VoucherCard $record, Action $action) => self::run($action, fn () => app(VoucherStock::class)->activate($record), 'تم التفعيل')),
                Action::make('void')->label('إلغاء / تالف')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (VoucherCard $r) => $r->status === 'available' && auth()->user()->can('stock.void'))
                    ->schema([TextInput::make('reason')->label('السبب')->required()->maxLength(300)])
                    ->action(fn (VoucherCard $record, array $data, Action $action) => self::run($action, fn () => app(VoucherStock::class)->void($record, $data['reason']), 'أُلغي الكارت')),
            ]);
    }

    public static function addAction(): Action
    {
        return Action::make('addCards')->label('إضافة أكواد')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => auth()->user()->can('stock.add'))
            ->modalSubmitActionLabel('حفظ')
            ->schema([
                Select::make('type')->label('النوع')->options(VoucherCard::TYPES)->required()->default('internet'),
                TextInput::make('denomination')->label('الفئة / القيمة')->integer()->minValue(1)->suffix('د.ع')->required()
                    ->datalist(VoucherCard::query()->distinct()->orderBy('denomination')->pluck('denomination')->all()),
                Textarea::make('codes')->label('أكواد الكروت')->required()->rows(8)
                    ->helperText('رقم في كل سطر، يمكن لصق عدة أرقام مرة واحدة. الأرقام المكررة تُتجاهل ولا تُضاف مرتين.'),
                Textarea::make('notes')->label('ملاحظات (اختياري)')->rows(2),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $result = app(VoucherStock::class)->add($data['type'], (int) $data['denomination'], preg_split('/[\r\n,]+/u', (string) $data['codes']), $data['notes'] ?? null);
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }
                $skipped = count($result['duplicates']) + count($result['rejected']);
                $notice = Notification::make()->title("أُضيف {$result['added']} كود بنجاح".($skipped ? " · لم يُضف {$skipped}" : ''));
                $details = array_filter([
                    $result['duplicates'] ? 'مكررة ('.count($result['duplicates']).'): '.implode('، ', array_slice($result['duplicates'], 0, 15)) : null,
                    $result['rejected'] ? 'مرفوضة لطولها ('.count($result['rejected']).'): '.implode('، ', array_slice($result['rejected'], 0, 15)) : null,
                ]);
                $details === [] ? $notice->success() : $notice->warning()->body(implode("\n", $details))->persistent();
                $notice->send();
            });
    }

    private static function run(Action $action, \Closure $callback, string $done): void
    {
        try {
            $callback();
            Notification::make()->success()->title($done)->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ListVoucherCards::route('/')];
    }
}
