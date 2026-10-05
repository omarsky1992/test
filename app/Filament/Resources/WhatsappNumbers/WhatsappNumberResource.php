<?php

namespace App\Filament\Resources\WhatsappNumbers;

use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\WhatsappNumbers\Pages\ManageWhatsappNumbers;
use App\Models\User;
use App\Models\WhatsappNumber;
use App\WhatsApp\NumberRegistry;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class WhatsappNumberResource extends Resource
{
    protected static ?string $model = WhatsappNumber::class;

    protected static ?string $slug = 'whatsapp-numbers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'رقم مصرح';

    protected static ?string $pluralModelLabel = 'الأرقام المصرح لها';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('whatsapp.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('phone')->label('رقم واتساب')->required()->tel()->extraInputAttributes(['dir' => 'ltr'])
                ->placeholder('07701234567')->helperText('أي صيغة: 0770…، ‎+964 770…، 964770…'),
            TextInput::make('label')->label('التسمية')->maxLength(100)->placeholder('مثال: هاتف المدير'),
            Select::make('user_id')->label('يعمل بصلاحيات المستخدم')->required()
                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->helperText('كل أمر من هذا الرقم يُنفَّذ ويُسجَّل باسم هذا المستخدم وبصلاحياته فقط.'),
            Toggle::make('is_active')->label('مفعّل')->default(true),
            Toggle::make('receives_alerts')->label('يستلم التنبيهات')->default(false)
                ->helperText('يجب التفعيل، ومن في الديون الثانوية وينتهي اشتراكه خلال 24 ساعة.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'creator']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('phone')->label('الرقم')->weight('bold')->searchable()->extraAttributes(['dir' => 'ltr'])->copyable(),
                TextColumn::make('label')->label('التسمية')->searchable()->placeholder('—'),
                TextColumn::make('user.name')->label('المستخدم المرتبط'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
                IconColumn::make('receives_alerts')->label('يستلم التنبيهات')->boolean(),
                TextColumn::make('last_used_at')->label('آخر استخدام')->dateTime('Y/m/d H:i')->placeholder('—'),
                TextColumn::make('creator.name')->label('أضافه')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('edit')->label('تعديل')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (WhatsappNumber $r) => $r->only(['phone', 'label', 'user_id', 'is_active', 'receives_alerts']))
                    ->schema(self::fields())
                    ->action(fn (array $data, WhatsappNumber $record, Action $action) => self::run($action, fn () => app(NumberRegistry::class)->update(
                        $record, $data['phone'], User::findOrFail($data['user_id']), $data['label'] ?? null, (bool) $data['is_active'], (bool) ($data['receives_alerts'] ?? false),
                    ))),
                Action::make('toggle')->label(fn (WhatsappNumber $r) => $r->is_active ? 'تعطيل' : 'تفعيل')
                    ->icon(fn (WhatsappNumber $r) => $r->is_active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                    ->color(fn (WhatsappNumber $r) => $r->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (WhatsappNumber $record, Action $action) => self::run($action, fn () => app(NumberRegistry::class)->setActive($record, ! $record->is_active))),
                Action::make('delete')->label('حذف')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->requiresConfirmation()->modalDescription('يبقى سجل رسائله وعملياته محفوظاً.')
                    ->action(fn (WhatsappNumber $record, Action $action) => self::run($action, fn () => app(NumberRegistry::class)->delete($record))),
            ]);
    }

    public static function addAction(): Action
    {
        return Action::make('add')->label('إضافة رقم')->icon(Heroicon::OutlinedPlus)
            ->authorize('whatsapp.manage')
            ->schema(self::fields())
            ->action(fn (array $data, Action $action) => self::run($action, fn () => app(NumberRegistry::class)->add(
                $data['phone'], User::findOrFail($data['user_id']), $data['label'] ?? null, (bool) ($data['is_active'] ?? true), (bool) ($data['receives_alerts'] ?? false),
            )));
    }

    public static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
            Notification::make()->success()->title('تم الحفظ')->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageWhatsappNumbers::route('/')];
    }
}
