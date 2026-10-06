<?php

namespace App\Filament\Resources\CommandPatterns;

use App\Filament\Resources\CommandPatterns\Pages\ManageCommandPatterns;
use App\Models\CommandPattern;
use App\Services\Audit;
use App\WhatsApp\Command;
use App\WhatsApp\CustomPatterns;
use App\WhatsApp\RuleInterpreter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * صيغ الأوامر: the admin's own phrasings for WhatsApp commands, so everyone writes (or says)
 * the same words: «تفعيل {الاسم} {الأيام}», «قبض {الاسم} {المبلغ}»…
 */
class CommandPatternResource extends Resource
{
    protected static ?string $model = CommandPattern::class;

    protected static ?string $slug = 'command-patterns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static string|UnitEnum|null $navigationGroup = 'واتساب';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'صيغة';

    protected static ?string $pluralModelLabel = 'صيغ الأوامر';

    public static function canViewAny(): bool
    {
        return auth()->user()->can('whatsapp.manage');
    }

    public static function form(Schema $schema): Schema
    {
        $blanks = collect(CommandPattern::BLANKS)->map(fn ($label, $key) => "{$key} = {$label}")->implode(' · ');

        return $schema->components([
            TextInput::make('pattern')->label('الصيغة')->required()->maxLength(200)->columnSpanFull()
                ->placeholder('تفعيل {الاسم} {الأيام}')
                ->helperText("اكتب الكلمات كما يكتبها الموظف أو يقولها، وضع الفراغات: {$blanks}")
                ->rules([fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                    $missing = array_filter(CommandPattern::ACTIONS[$get('action')][1] ?? [], fn ($b) => ! str_contains((string) $value, $b));
                    if ($missing) {
                        $fail('هذه العملية تحتاج في الصيغة: '.implode(' و', $missing));
                    }
                    if ($get('action') === 'activate' && ! str_contains((string) $value, '{الأيام}') && ! str_contains((string) $value, '{الايام}') && blank($get('default_days'))) {
                        $fail('للتفعيل ضع {الأيام} في الصيغة، أو حدد «عدد الأيام إذا لم تُذكر».');
                    }
                }]),
            Select::make('action')->label('ماذا تفعل')->required()->live()
                ->options(collect(CommandPattern::ACTIONS)->map(fn ($a) => $a[0])->all()),
            TextInput::make('default_days')->label('عدد الأيام إذا لم تُذكر')->integer()->minValue(1)->maxValue(365)
                ->visible(fn (Get $get) => $get('action') === 'activate'),
            Toggle::make('amount_in_thousands')->label('المبلغ بالآلاف (35 = 35,000)')
                ->visible(fn (Get $get) => in_array($get('action'), ['payment', 'add_debt_primary', 'add_debt_secondary', 'void_debt', 'purchase', 'sale'], true)),
            TextInput::make('sort_order')->label('الترتيب')->integer()->default(0)
                ->helperText('الأصغر يُجرَّب أولاً. ضع الصيغ الأطول قبل الأقصر.'),
            Toggle::make('is_active')->label('فعّالة')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('pattern')->label('الصيغة')->weight('bold')->searchable(),
                TextColumn::make('action')->label('العملية')->badge()->formatStateUsing(fn (string $state) => CommandPattern::ACTIONS[$state][0] ?? $state),
                TextColumn::make('default_days')->label('أيام افتراضية')->placeholder('—'),
                IconColumn::make('amount_in_thousands')->label('بالآلاف')->boolean(),
                IconColumn::make('is_active')->label('فعّالة')->boolean(),
            ])
            ->recordActions([
                EditAction::make()->after(fn (Model $record) => app(Audit::class)->log('command_pattern.updated', $record, null, $record->only(['pattern', 'action', 'is_active']))),
                DeleteAction::make()->before(fn (Model $record) => app(Audit::class)->log('command_pattern.deleted', $record, $record->only(['pattern', 'action']), null)),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])
            ->after(fn (Model $record) => app(Audit::class)->log('command_pattern.created', $record, null, $record->only(['pattern', 'action'])));
    }

    /** Shows how a message would be understood, without running anything. */
    public static function testAction(): Action
    {
        return Action::make('test')->label('تجربة رسالة')->icon(Heroicon::OutlinedBeaker)->color('gray')
            ->schema([TextInput::make('message')->label('اكتب الرسالة كما يرسلها الموظف')->required()])
            ->modalSubmitActionLabel('افحص')
            ->action(function (array $data, Action $action) {
                $command = app(CustomPatterns::class)->match($data['message']);
                $source = 'صيغة مخصصة';
                if (! $command) {
                    $command = app(RuleInterpreter::class)->interpret($data['message']);
                    $source = 'الأوامر الجاهزة';
                }
                $command
                    ? Notification::make()->success()->title("مفهومة ({$source})")->body(self::describe($command))->persistent()->send()
                    : Notification::make()->warning()->title('غير مفهومة')->body('لا تطابق أي صيغة ولا أمراً جاهزاً. أضف صيغة لها، أو تُرسل للذكاء الاصطناعي إذا كان مفعّلاً.')->persistent()->send();
                $action->halt();
            });
    }

    public static function describe(Command $c): string
    {
        $label = match ($c->intent) {
            'activate' => 'تفعيل', 'payment' => 'قبض', 'add_debt' => $c->bucket === 'secondary' ? 'دين ثانوي' : 'دين أولي',
            'transfer' => 'مناقلة', 'void_debt' => 'مسح دين', 'purchase' => 'مشتريات', 'sale' => 'مبيعات', 'query' => 'استعلام',
            default => $c->intent,
        };
        $parts = array_filter([
            "العملية: {$label}",
            $c->subscriber ? "المشترك: {$c->subscriber}" : null,
            $c->days ? "الأيام: {$c->days}" : null,
            $c->amount ? 'المبلغ: '.number_format($c->amount) : null,
            $c->query ? "الاستعلام: {$c->query}" : null,
            $c->items ? 'المواد: '.implode('، ', array_map(fn ($i) => $i['description'].' '.number_format($i['total'] ?? (($i['quantity'] ?? 1) * ($i['unit_price'] ?? 0))), $c->items)) : null,
        ]);

        return implode("\n", $parts);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommandPatterns::route('/')];
    }
}
