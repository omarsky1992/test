<?php

namespace App\Filament\Resources\Promotions;

use App\Enums\DiscountType;
use App\Enums\PromotionAudience;
use App\Enums\PromotionFunding;
use App\Filament\Resources\Promotions\Pages\ManagePromotions;
use App\Models\Promotion;
use App\Models\ServicePlan;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PromotionResource extends Resource
{
    protected static ?string $model = Promotion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'عرض';

    protected static ?string $pluralModelLabel = 'العروض';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('promotions.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('اسم العرض')->required()->maxLength(120)->columnSpanFull(),
            ToggleButtons::make('audience')->label('لمن')->options(PromotionAudience::class)->inline()->required(),
            Select::make('plan_id')->label('الفئة')->placeholder('كل الفئات')->options(fn () => ServicePlan::pluck('name_ar', 'id')),
            ToggleButtons::make('discount_type')->label('نوع الخصم')->options(DiscountType::class)->inline()->required(),
            TextInput::make('discount_value')->label('القيمة')->integer()->minValue(1)->required()
                ->helperText('سعر ثابت أو مبلغ بالدينار، أو نسبة من 1 إلى 100.'),
            ToggleButtons::make('funded_by')->label('من يتحمّل الخصم')->options(PromotionFunding::class)->inline()->default('company')->required()
                ->helperText('الشركة: تخصم منكم السعر المخفّض. الوكيل: الشركة تأخذ السعر كاملاً ويُسجَّل الفرق مصروفاً.'),
            Toggle::make('is_active')->label('فعّال')->default(true),
            DateTimePicker::make('starts_at')->label('يبدأ')->seconds(false)->required()->default(now()),
            DateTimePicker::make('ends_at')->label('ينتهي')->seconds(false)->required()->after('starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('العرض')->weight('bold'),
                TextColumn::make('audience')->label('لمن')->badge(),
                TextColumn::make('plan.name_ar')->label('الفئة')->placeholder('الكل'),
                TextColumn::make('discount_type')->label('النوع')->badge(),
                TextColumn::make('discount_value')->label('القيمة'),
                TextColumn::make('funded_by')->label('على')->badge(),
                TextColumn::make('starts_at')->label('من')->dateTime('Y/m/d'),
                TextColumn::make('ends_at')->label('إلى')->dateTime('Y/m/d'),
                IconColumn::make('is_active')->label('فعّال')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->using(function (Model $record, array $data) {
                    $original = $record->getAttributes();
                    $record->update($data);
                    app(Audit::class)->changes('promotion.updated', $record, $original);

                    return $record;
                }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => auth()->id()])
            ->after(fn (Model $record) => app(Audit::class)->log('promotion.created', $record, null, $record->only(['name', 'discount_type', 'discount_value'])));
    }

    public static function getPages(): array
    {
        return ['index' => ManagePromotions::route('/')];
    }
}
