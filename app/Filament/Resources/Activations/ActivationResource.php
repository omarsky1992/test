<?php

namespace App\Filament\Resources\Activations;

use App\Enums\ActivationKind;
use App\Enums\CompletionStatus;
use App\Enums\DocumentStatus;
use App\Filament\Actions\Operations;
use App\Filament\Resources\Activations\Pages\ListActivations;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Activation;
use App\Models\ServicePlan;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ActivationResource extends Resource
{
    protected static ?string $model = Activation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات اليومية';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'تفعيل';

    protected static ?string $pluralModelLabel = 'التفعيلات';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return static::buildTable($table, showSubscriber: true);
    }

    public static function buildTable(Table $table, bool $showSubscriber): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['subscriber', 'account', 'plan', 'debt', 'creator']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable()->toggleable(),
                TextColumn::make('subscriber.full_name')->label('المشترك')->weight('bold')->visible($showSubscriber)
                    ->url(fn (Activation $r) => SubscriberResource::getUrl('view', ['record' => $r->subscriber_id])),
                TextColumn::make('account.username')->label('اليوزر')->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('plan.name_ar')->label('الفئة'),
                TextColumn::make('kind')->label('النوع')->badge(),
                TextColumn::make('starts_at')->label('البداية')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('ends_at')->label('النهاية')->dateTime('Y/m/d H:i')->sortable(),
                TextColumn::make('final_price')->label('السعر')->formatStateUsing(fn ($state) => Money::format($state, false))
                    ->description(fn (Activation $r) => $r->discount_amount ? 'خصم '.Money::format($r->discount_amount, false) : null),
                TextColumn::make('completion_status')->label('الإكمال')->badge()
                    ->description(fn (Activation $r) => $r->completed_via?->getLabel()),
                TextColumn::make('time_status')->label('الوضع')->badge()
                    ->state(fn (Activation $r) => self::timeStatus($r)[0])
                    ->color(fn (Activation $r) => self::timeStatus($r)[1]),
                IconColumn::make('start_overridden')->label('تاريخ معدّل')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')->label('الموظف')->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label('النوع')->options(ActivationKind::class),
                SelectFilter::make('completion_status')->label('الإكمال')->options(CompletionStatus::class),
                SelectFilter::make('plan_id')->label('الفئة')->options(fn () => ServicePlan::pluck('name_ar', 'id')),
                SelectFilter::make('created_by')->label('الموظف')->options(fn () => User::pluck('name', 'id')),
                TernaryFilter::make('start_overridden')->label('تاريخ بداية معدّل'),
                SelectFilter::make('status')->label('الحالة')->options(DocumentStatus::class)->default(DocumentStatus::Posted->value),
                Filter::make('created_at')->label('التاريخ')->schema([
                    DatePicker::make('from')->label('من'),
                    DatePicker::make('until')->label('إلى'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($query, $d) => $query->where('created_at', '>=', $d))
                    ->when($data['until'] ?? null, fn ($query, $d) => $query->where('created_at', '<', now()->parse($d)->addDay()))),
            ])
            ->recordActions([
                Operations::pay(),
                Operations::transfer(),
                ActionGroup::make([
                    Operations::followUp(),
                    Operations::editStart(),
                    Operations::voidActivation(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivations::route('/')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function timeStatus(Activation $activation): array
    {
        $now = now();

        return match (true) {
            $activation->status === DocumentStatus::Voided => ['ملغى', 'gray'],
            $now->lessThan($activation->starts_at) => ['مجدول', 'info'],
            $now->lessThan($activation->ends_at) && $activation->completion_status === CompletionStatus::Pending => ['فعّال — بانتظار الإكمال', 'warning'],
            $now->lessThan($activation->ends_at) => ['فعّال', 'success'],
            $activation->completion_status === CompletionStatus::Pending => ['منتهٍ — غير مكتمل', 'danger'],
            default => ['منتهٍ', 'gray'],
        };
    }
}
