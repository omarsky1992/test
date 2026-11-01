<?php

namespace App\Filament\Platform\Resources\Tenants;

use App\Filament\Platform\Resources\Tenants\Pages\ListTenants;
use App\Platform\PlatformAudit;
use App\Platform\Subscription;
use App\Platform\Tenant;
use App\Platform\TenantProvisioner;
use App\Platform\TenantStats;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The clients: add, edit, extend, suspend, archive (never delete: an archived client keeps every
 * record and can be restored). Client 1 (permanent) cannot be suspended or archived.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $slug = 'tenants';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $modelLabel = 'عميل';

    protected static ?string $pluralModelLabel = 'العملاء';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false; // through the «إضافة عميل» action
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')->label('المعرّف')->sortable()->searchable(),
                TextColumn::make('name')->label('العميل')->weight('bold')->searchable()
                    ->description(fn (Tenant $t) => $t->owner_name),
                TextColumn::make('domain')->label('الرابط')->searchable(['domain', 'slug'])->copyable()
                    ->url(fn (Tenant $t) => $t->domain ? 'https://'.$t->domain : null)->openUrlInNewTab(),
                TextColumn::make('state')->label('الاشتراك')->badge()
                    ->state(fn (Tenant $t) => Subscription::state($t))
                    ->formatStateUsing(fn (string $state) => Subscription::STATES[$state] ?? $state)
                    ->color(fn (string $state) => Subscription::COLORS[$state] ?? 'gray'),
                TextColumn::make('subscription_starts_at')->label('البداية')->date('Y/m/d')->sortable(),
                TextColumn::make('subscription_ends_at')->label('الانتهاء')->date('Y/m/d')->sortable()->placeholder('—')
                    ->description(fn (Tenant $t) => $t->trial_ends_at ? 'تجربة حتى '.$t->trial_ends_at->setTimezone(Subscription::tz())->format('Y/m/d') : null),
                TextColumn::make('plan')->label('الباقة')->placeholder('—'),
                TextColumn::make('subscribers_count')->label('المشتركون')->numeric()->sortable(),
                TextColumn::make('users_count')->label('الموظفون')->numeric()->sortable(),
                TextColumn::make('created_at')->label('أُنشئ')->date('Y/m/d')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(['active' => 'فعّال', 'suspended' => 'موقوف', 'archived' => 'مؤرشف']),
                Filter::make('expiring')->label('ينتهي خلال 7 أيام')
                    ->query(fn (Builder $q) => $q->where('is_permanent', false)->whereBetween('subscription_ends_at', [now(), now()->addDays((int) config('platform.expiring_days'))])),
                Filter::make('expired')->label('منتهي')
                    ->query(fn (Builder $q) => $q->where('is_permanent', false)->where('subscription_ends_at', '<', now())),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::editAction(),
                    self::extendAction(),
                    self::suspendAction(),
                    self::archiveAction(),
                    Action::make('refreshStats')->label('تحديث الأرقام')->icon(Heroicon::OutlinedArrowPath)
                        ->action(fn (Tenant $record) => app(TenantStats::class)->refresh($record)),
                ]),
            ]);
    }

    /** «إضافة عميل جديد»: its own database, its manager account and its address. */
    public static function createAction(): Action
    {
        return Action::make('createTenant')->label('إضافة عميل جديد')->icon(Heroicon::OutlinedPlus)
            ->modalWidth(Width::FourExtraLarge)
            ->fillForm(fn () => ['password' => Str::password(12, symbols: false), 'duration' => '1m', 'starts_on' => now()->setTimezone(Subscription::tz())->toDateString(), 'grace_days' => 0, 'trial_days' => 0])
            ->schema([
                Section::make('العميل')->columns(2)->schema([
                    TextInput::make('name')->label('اسم العميل أو الوكالة')->required()->maxLength(120),
                    TextInput::make('owner_name')->label('اسم صاحب الحساب')->maxLength(120),
                    TextInput::make('phone')->label('رقم الهاتف')->tel()->maxLength(20),
                    TextInput::make('slug')->label('الرابط')->required()->maxLength(30)
                        ->suffix(fn () => '.'.config('platform.base_domain'))
                        ->helperText('أحرف إنكليزية صغيرة وأرقام وشرطة، مثل: alamal'),
                    Textarea::make('notes')->label('ملاحظات إدارية')->rows(2)->columnSpanFull(),
                ]),
                Section::make('حساب مدير العميل')->columns(2)->schema([
                    TextInput::make('username')->label('اسم المستخدم')->required()->maxLength(60)->alphaDash(),
                    TextInput::make('password')->label('كلمة مرور مؤقتة')->required()->minLength(8)->maxLength(100)
                        ->helperText('أعطها للعميل، وليغيّرها بعد أول دخول. لا تُحفظ في سجلات المنصة.'),
                ]),
                Section::make('الاشتراك')->columns(3)->schema(self::subscriptionFields(withStart: true)),
            ])
            ->modalSubmitActionLabel('إنشاء العميل')
            ->action(function (array $data, Action $action) {
                $starts = CarbonImmutable::parse($data['starts_on'], Subscription::tz())->startOfDay();
                $trialDays = (int) ($data['trial_days'] ?? 0);
                try {
                    $tenant = app(TenantProvisioner::class)->create([
                        'name' => $data['name'], 'slug' => $data['slug'], 'owner_name' => $data['owner_name'] ?? null, 'phone' => $data['phone'] ?? null,
                        'username' => $data['username'], 'password' => $data['password'], 'notes' => $data['notes'] ?? null, 'plan' => $data['plan'] ?? null,
                        // A trial alone: after it the owner extends with a paid period.
                        'starts_at' => $starts,
                        'ends_at' => $trialDays > 0 ? null : Subscription::endFrom($starts, $data['duration'], (int) ($data['days'] ?? 0), $data['until'] ?? null),
                        'trial_ends_at' => $trialDays > 0 ? $starts->addDays($trialDays) : null,
                        'grace_days' => (int) ($data['grace_days'] ?? 0),
                    ]);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();

                    return;
                }
                Notification::make()->success()->persistent()->title("أُنشئ العميل «{$tenant->name}»")
                    ->body("الرابط: https://{$tenant->domain}\nالمستخدم: {$data['username']}\nكلمة المرور: المؤقتة التي كانت في النموذج (لا تُحفظ في المنصة).")
                    ->send();
            });
    }

    private static function subscriptionFields(bool $withStart): array
    {
        return array_values(array_filter([
            $withStart ? DatePicker::make('starts_on')->label('تاريخ البداية')->required()->native(false)->displayFormat('Y/m/d') : null,
            Select::make('duration')->label('المدة')->options(Subscription::DURATIONS)->live()
                ->required(fn (Get $get) => (int) $get('trial_days') === 0)->hidden(fn (Get $get) => (int) $get('trial_days') > 0),
            TextInput::make('days')->label('عدد الأيام')->integer()->minValue(1)->maxValue(3650)
                ->visible(fn (Get $get) => $get('duration') === 'days')->required(fn (Get $get) => $get('duration') === 'days'),
            DatePicker::make('until')->label('ينتهي في')->native(false)->displayFormat('Y/m/d')
                ->visible(fn (Get $get) => $get('duration') === 'date')->required(fn (Get $get) => $get('duration') === 'date'),
            $withStart ? TextInput::make('trial_days')->label('أيام التجربة المجانية')->integer()->minValue(0)->maxValue(90)->live(onBlur: true)
                ->helperText('0 = بدون تجربة. مع التجربة: بعد انتهائها تمدّد له مدة مدفوعة.') : null,
            $withStart ? TextInput::make('grace_days')->label('أيام السماح بعد الانتهاء')->integer()->minValue(0)->maxValue(30) : null,
            $withStart ? TextInput::make('plan')->label('الباقة')->maxLength(40) : null,
        ]));
    }

    private static function editAction(): Action
    {
        return Action::make('editTenant')->label('تعديل')->icon(Heroicon::OutlinedPencilSquare)
            ->fillForm(fn (Tenant $record) => $record->only(['name', 'owner_name', 'phone', 'plan', 'notes', 'grace_days']) + [
                'starts_on' => $record->subscription_starts_at?->setTimezone(Subscription::tz())->toDateString(),
                'ends_on' => $record->subscription_ends_at?->setTimezone(Subscription::tz())->toDateString(),
                'trial_on' => $record->trial_ends_at?->setTimezone(Subscription::tz())->toDateString(),
            ])
            ->schema(fn (Tenant $record) => [
                Grid::make(2)->schema([
                    TextInput::make('name')->label('اسم العميل')->required()->maxLength(120),
                    TextInput::make('owner_name')->label('صاحب الحساب')->maxLength(120),
                    TextInput::make('phone')->label('الهاتف')->maxLength(20),
                    TextInput::make('plan')->label('الباقة')->maxLength(40),
                    DatePicker::make('starts_on')->label('بداية الاشتراك')->native(false)->displayFormat('Y/m/d')->hidden($record->is_permanent),
                    DatePicker::make('ends_on')->label('نهاية الاشتراك')->native(false)->displayFormat('Y/m/d')->hidden($record->is_permanent),
                    DatePicker::make('trial_on')->label('نهاية التجربة')->native(false)->displayFormat('Y/m/d')->hidden($record->is_permanent),
                    TextInput::make('grace_days')->label('أيام السماح')->integer()->minValue(0)->maxValue(30)->hidden($record->is_permanent),
                    Textarea::make('notes')->label('ملاحظات')->rows(2)->columnSpanFull(),
                ]),
            ])
            ->action(function (array $data, Tenant $record) {
                $old = $record->only(['name', 'owner_name', 'phone', 'plan', 'notes', 'grace_days', 'subscription_starts_at', 'subscription_ends_at', 'trial_ends_at']);
                $date = fn (?string $d, bool $end) => filled($d) ? ($end ? CarbonImmutable::parse($d, Subscription::tz())->endOfDay() : CarbonImmutable::parse($d, Subscription::tz())->startOfDay()) : null;
                $record->fill(collect($data)->only(['name', 'owner_name', 'phone', 'plan', 'notes'])->all());
                if (! $record->is_permanent) {
                    $record->fill([
                        'grace_days' => (int) ($data['grace_days'] ?? 0),
                        'subscription_starts_at' => $date($data['starts_on'] ?? null, false),
                        'subscription_ends_at' => $date($data['ends_on'] ?? null, true),
                        'trial_ends_at' => $date($data['trial_on'] ?? null, true),
                    ]);
                }
                $record->save();
                app(PlatformAudit::class)->log('tenant.updated', $record, $old, $record->only(array_keys($old)));
                Notification::make()->success()->title('حُفظ')->send();
            });
    }

    private static function extendAction(): Action
    {
        return Action::make('extend')->label('تمديد الاشتراك')->icon(Heroicon::OutlinedCalendarDays)->color('success')
            ->hidden(fn (Tenant $record) => $record->is_permanent || $record->status === 'archived')
            ->fillForm(['duration' => '1m'])
            ->schema(self::subscriptionFields(withStart: false))
            ->modalDescription(fn (Tenant $record) => 'ينتهي حالياً: '.($record->subscription_ends_at?->setTimezone(Subscription::tz())->format('Y/m/d') ?? '—').'. التمديد من تاريخ الانتهاء إن لم يمضِ، وإلا من اليوم.')
            ->action(function (array $data, Tenant $record) {
                $old = $record->only(['subscription_ends_at', 'trial_ends_at']);
                $record->subscription_ends_at = Subscription::extendedEnd($record, $data['duration'], (int) ($data['days'] ?? 0), $data['until'] ?? null);
                $record->subscription_starts_at ??= now();
                $record->save();
                app(PlatformAudit::class)->log('tenant.extended', $record, $old, $record->only(['subscription_ends_at', 'trial_ends_at']));
                Notification::make()->success()->title('مُدّد حتى '.$record->subscription_ends_at->setTimezone(Subscription::tz())->format('Y/m/d'))->send();
            });
    }

    private static function suspendAction(): Action
    {
        return Action::make('toggleSuspend')
            ->label(fn (Tenant $record) => $record->status === 'suspended' ? 'إعادة التفعيل' : 'إيقاف إداري')
            ->icon(fn (Tenant $record) => $record->status === 'suspended' ? Heroicon::OutlinedPlay : Heroicon::OutlinedPause)
            ->color(fn (Tenant $record) => $record->status === 'suspended' ? 'success' : 'warning')
            ->hidden(fn (Tenant $record) => $record->is_permanent || $record->status === 'archived')
            ->requiresConfirmation()
            ->modalDescription(fn (Tenant $record) => $record->status === 'suspended'
                ? 'يعود العميل للعمل بكل بياناته.'
                : 'يتوقف العميل وموظفوه عن استخدام النظام. لا يُحذف أي شيء، وتعود البيانات كما هي عند إعادة التفعيل.')
            ->action(function (Tenant $record) {
                $old = $record->status;
                $record->update(['status' => $old === 'suspended' ? 'active' : 'suspended']);
                app(PlatformAudit::class)->log($record->status === 'suspended' ? 'tenant.suspended' : 'tenant.reactivated', $record, ['status' => $old], ['status' => $record->status]);
            });
    }

    /** Archiving keeps the database and every record; the address stops answering. Restorable. */
    private static function archiveAction(): Action
    {
        return Action::make('toggleArchive')
            ->label(fn (Tenant $record) => $record->status === 'archived' ? 'استعادة من الأرشيف' : 'أرشفة')
            ->icon(Heroicon::OutlinedArchiveBox)->color('danger')
            ->hidden(fn (Tenant $record) => $record->is_permanent)
            ->requiresConfirmation()
            ->modalDescription(fn (Tenant $record) => $record->status === 'archived'
                ? 'يعود العميل (موقوفاً، فعّله بعدها إن أردت).'
                : 'يتوقف رابط العميل نهائياً حتى الاستعادة. لا تُحذف قاعدة بياناته ولا أي سجل.')
            ->schema(fn (Tenant $record) => $record->status === 'archived' ? [] : [
                TextInput::make('confirm')->label("اكتب «{$record->slug}» للتأكيد")->required()->in([$record->slug]),
            ])
            ->action(function (Tenant $record) {
                $old = $record->status;
                $restoring = $old === 'archived';
                $record->update(['status' => $restoring ? 'suspended' : 'archived', 'archived_at' => $restoring ? null : now()]);
                app(PlatformAudit::class)->log($restoring ? 'tenant.restored' : 'tenant.archived', $record, ['status' => $old], ['status' => $record->status]);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListTenants::route('/')];
    }
}
