<?php

namespace App\Filament\Resources\ServicePlans;

use App\Filament\Resources\ServicePlans\Pages\ManageServicePlans;
use App\Models\ServicePlan;
use App\Services\Audit;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ServicePlanResource extends Resource
{
    protected static ?string $model = ServicePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'فئة';

    protected static ?string $pluralModelLabel = 'الفئات والأسعار';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('plans.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_ar')->label('الاسم')->required()->maxLength(60),
            TextInput::make('code')->label('الرمز')->required()->maxLength(30)->unique(ignoreRecord: true)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('price')->label('السعر')->integer()->minValue(1)->suffix('د.ع')->required()
                ->helperText('تغيير السعر لا يؤثر على التفعيلات السابقة.'),
            TextInput::make('sort_order')->label('الترتيب')->integer()->default(0),
            Toggle::make('is_active')->label('فعّالة')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label('الفئة')->weight('bold'),
                TextColumn::make('price')->label('السعر')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('duration_days')->label('المدة (يوم)'),
                IconColumn::make('is_active')->label('فعّالة')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->using(function (Model $record, array $data) {
                    $original = $record->getAttributes();
                    $record->update($data);
                    app(Audit::class)->changes('plan.updated', $record, $original);

                    return $record;
                }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->after(fn (Model $record) => app(Audit::class)->log('plan.created', $record, null, $record->only(['code', 'price'])));
    }

    public static function getPages(): array
    {
        return ['index' => ManageServicePlans::route('/')];
    }
}
